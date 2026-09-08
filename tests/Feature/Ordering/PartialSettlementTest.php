<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Ordering\Actions\SettleAbandonedItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\StateMachine\OrderItemStateMachine;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * ТЗ 1.2 и 1.3: что смогли выдать — остаётся, за остальное деньги возвращаются,
 * и по деньгам всегда сходится.
 *
 * Главная проверка здесь не «возврат случился», а «деньги сошлись». Инвариант
 * записан так, что его проверяет база: у заказа, дошедшего до конечного
 * состояния, остаток по customer_prepayment равен нулю. Обнулить его может
 * только выдача (в выручку) или возврат (в долг перед покупателем) — третьего
 * способа нет.
 */
final class PartialSettlementTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private const SKUS = ['KEY-CS2-PRIME', 'KEY-GTA5', 'GIFT-PSN-1000'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    #[Test]
    public function a_delivered_item_stays_and_the_rest_is_refunded(): void
    {
        $order = $this->paidOrder();

        // Первая позиция выдаётся, две другие заходят в тупик: поставщиков
        // у них нет, товара тоже.
        $this->deliverOnlyFirstItem($order);

        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());

        $this->travel(61)->minutes();

        self::assertSame(2, app(SettleAbandonedItems::class)->execute());

        $statuses = $this->itemStatuses($order);

        // Выданное остаётся у покупателя — возврат его не отменяет.
        self::assertSame(OrderItemStatus::Delivered->value, $statuses[1]);
        self::assertSame(OrderItemStatus::Refunded->value, $statuses[2]);
        self::assertSame(OrderItemStatus::Refunded->value, $statuses[3]);

        // Заказ рассчитан, и это КОНЕЧНОЕ состояние: возвращаться сюда незачем.
        $order->refresh();
        self::assertSame(OrderStatus::PartiallyDelivered, $order->status);
        self::assertTrue($order->status->isFinal());

        // Выдача осталась ровно одна: возврат не тронул отданный код.
        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
    }

    #[Test]
    public function the_money_adds_up_paid_equals_delivered_plus_refunded(): void
    {
        $order = $this->paidOrder();
        $this->deliverOnlyFirstItem($order);

        $this->travel(61)->minutes();
        app(SettleAbandonedItems::class)->execute();

        $paid = $order->amount_minor;
        $revenue = $this->accountTotal($order, 'revenue');
        $refunded = $this->accountTotal($order, 'refunds_payable');

        // Дословно требование ТЗ 1.3.
        self::assertSame(
            $paid,
            $revenue + $refunded,
            "Оплачено {$paid}, признано выручкой {$revenue}, возвращено {$refunded} — не сходится.",
        );

        // И то же самое одним числом: обязательство перед покупателем закрыто.
        self::assertSame(0, $this->accountTotal($order, 'customer_prepayment'));
    }

    #[Test]
    public function refunding_twice_does_not_return_the_money_twice(): void
    {
        // Вернуть деньги дважды ровно настолько же плохо, как выдать код
        // дважды: в первом случае теряется товар, во втором — деньги.
        $order = $this->paidOrder();
        $this->deliverOnlyFirstItem($order);

        $this->travel(61)->minutes();

        self::assertSame(2, app(SettleAbandonedItems::class)->execute());

        // Повторные прогоны: расписание запускает расчёт каждую минуту.
        self::assertSame(0, app(SettleAbandonedItems::class)->execute());
        self::assertSame(0, app(SettleAbandonedItems::class)->execute());

        self::assertSame(
            2,
            DB::table('ledger_transactions')
                ->where('order_id', $order->id)
                ->where('kind', 'item_refunded')
                ->count(),
            'Проводок возврата больше, чем невыданных позиций.',
        );

        self::assertSame(0, $this->accountTotal($order, 'customer_prepayment'));
    }

    #[Test]
    public function an_order_where_nothing_could_be_delivered_ends_as_refunded(): void
    {
        $order = $this->paidOrder();

        // Ни одна позиция не выдана: склад пуст по всем трём товарам.
        foreach ($order->items as $item) {
            $this->emptyThePool($item);
        }

        app(DeliverOrder::class)->execute($order->public_id);

        $this->travel(61)->minutes();
        app(SettleAbandonedItems::class)->execute();

        $order->refresh();
        self::assertSame(OrderStatus::Refunded, $order->status);
        self::assertTrue($order->status->isFinal());
        self::assertSame($order->amount_minor, $this->accountTotal($order, 'refunds_payable'));
        self::assertSame(0, $this->accountTotal($order, 'customer_prepayment'));
    }

    #[Test]
    public function a_fresh_dead_end_is_not_refunded_yet(): void
    {
        // Терпение обязательно: товар мог появиться на складе минутой позже,
        // а возврат необратим. Закрывать заказ сразу после первого отказа
        // означало бы возвращать деньги там, где надо было подождать.
        $order = $this->paidOrder();
        $this->deliverOnlyFirstItem($order);

        self::assertSame(0, app(SettleAbandonedItems::class)->execute());

        // Заказ ждёт пополнения склада, а не закрыт: из этого состояния
        // ещё есть выход назад в выдачу.
        self::assertSame(OrderStatus::OutOfStock, $order->refresh()->status);
        self::assertFalse($order->status->isFinal(), 'Тупик обязан оставаться проходимым: из него ещё есть выход.');
        self::assertSame(0, DB::table('ledger_transactions')->where('kind', 'item_refunded')->count());
    }

    #[Test]
    public function an_item_of_unknown_fate_is_never_refunded(): void
    {
        // Самый опасный случай возврата. Позиция висит в delivering: судьба
        // обращения к поставщику НЕИЗВЕСТНА, код мог быть выдан. Вернуть за неё
        // деньги значит отдать и товар, и деньги — поэтому такие позиции
        // в расчёт не берутся никогда, сколько бы они ни висели.
        $order = $this->paidOrder();
        $machine = app(OrderItemStateMachine::class);

        foreach ($order->items as $item) {
            $machine->tryTransition($item, OrderItemStatus::Delivering, reason: 'fixture');
        }

        $this->travel(10)->days();

        self::assertSame(0, app(SettleAbandonedItems::class)->execute());
        self::assertSame(
            0,
            DB::table('ledger_transactions')->where('kind', 'item_refunded')->count(),
        );
    }

    /**
     * Выдать первую позицию, остальным оставить пустой склад.
     *
     * Первая версия фикстуры просто переводила позиции в delivery_failed —
     * и тест падал, потому что система их ШТАТНО ПОВТОРИЛА и выдала. Это
     * оказалось правдой о системе, а не о тесте: delivery_failed — состояние
     * восстановимое, из него есть выход назад в выдачу.
     *
     * Настоящий тупик, из которого выдача невозможна, — отсутствие товара.
     * Его и воспроизводим.
     */
    private function deliverOnlyFirstItem(Order $order): void
    {
        foreach ($order->items->slice(1) as $item) {
            $this->emptyThePool($item);
        }

        app(DeliverOrder::class)->execute($order->public_id);
    }

    /**
     * Опустошить пул ключей под товар позиции.
     */
    private function emptyThePool(OrderItem $item): void
    {
        DB::table('license_keys')
            ->where('product_id', $item->product_id)
            ->where('status', 'available')
            ->update(['status' => 'reserved', 'reserved_at' => now(), 'reserved_until' => now()->addYear()]);
    }

    /**
     * Сальдо счёта по заказу в копейках, со знаком «кредит минус дебет».
     */
    private function accountTotal(Order $order, string $account): int
    {
        $total = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.order_id', $order->id)
            ->where('a.code', $account)
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS balance")
            ->value('balance');

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return array<int, string>
     */
    private function itemStatuses(Order $order): array
    {
        $statuses = [];

        foreach (DB::table('order_items')->where('order_id', $order->id)->orderBy('line_no')->get() as $row) {
            $statuses[(int) $row->line_no] = (string) $row->status;
        }

        return $statuses;
    }

    private function paidOrder(): Order
    {
        $order = $this->makeMultiOrder(self::SKUS);
        $eventId = 'evt_settle_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
