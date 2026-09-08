<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * ТЗ 1.5: «заказ доходит до конечного состояния даже после аварийной остановки
 * и перезапуска в середине выдачи».
 *
 * Проверяется именно СЕРЕДИНА: часть позиций уже выдана, часть — нет, и воркер
 * умер между ними. Это состояние ничем не похоже на «выдача не начиналась»:
 * заказ висит в промежуточном статусе, задачи в очереди нет, а из позиций одни
 * закрыты, другие держат протухшую аренду.
 *
 * Пока статус заказа выставлялся оптимистично в конце удачного пути, из такого
 * состояния выхода не было вовсе — некому было его пересчитать. Выводимый
 * статус даёт выход: любой следующий проход считает его заново из того, что
 * реально лежит в базе.
 */
final class MultiItemRecoveryTest extends TestCase
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
    public function an_order_abandoned_halfway_through_delivery_still_reaches_a_final_state(): void
    {
        $order = $this->paidOrder();

        // Третью позицию взял другой воркер и держит прямо сейчас.
        $stuckItem = $this->holdLastItem($order);

        // Первый проход выдаёт первые две, третью не трогает — она занята.
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(2, DB::table('deliveries')->where('order_id', $order->id)->count());

        // Вот она, середина: часть заказа выдана, часть нет, и заказ в
        // промежуточном статусе. Ни один статусный фильтр «оплачен, но не
        // выдан» его уже не увидит.
        self::assertSame(OrderStatus::Delivering, $order->refresh()->status);

        // Воркер умирает, не закончив.
        $this->killTheWorker($stuckItem);

        // Перезапуск: обычный путь выдачи, никакой отдельной логики восстановления.
        app(DeliverOrder::class)->execute($order->public_id);

        // Заказ дошёл до конечного состояния, и все позиции закрыты.
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
        self::assertTrue($order->status->isFinal());
        self::assertSame(3, DB::table('deliveries')->where('order_id', $order->id)->count());

        self::assertSame(
            0,
            DB::table('order_items')
                ->where('order_id', $order->id)
                ->where('status', '!=', OrderItemStatus::Delivered->value)
                ->count(),
            'Часть позиций осталась незакрытой после восстановления.',
        );
    }

    #[Test]
    public function recovery_does_not_hand_out_a_second_code_for_items_already_delivered(): void
    {
        // Обратная сторона восстановления и самая дорогая ошибка: повтор не
        // имеет права выдать второй код по позиции, которая уже закрыта.
        $order = $this->paidOrder();

        $stuckItem = $this->holdLastItem($order);
        app(DeliverOrder::class)->execute($order->public_id);
        $this->killTheWorker($stuckItem);

        // Три прогона подряд: очередь доставляет at-least-once, и повтор
        // восстановления — такая же норма, как повтор обычной выдачи.
        app(DeliverOrder::class)->execute($order->public_id);
        app(DeliverOrder::class)->execute($order->public_id);
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(3, DB::table('deliveries')->where('order_id', $order->id)->count());

        // Ключей израсходовано ровно три — по одному на позицию.
        self::assertSame(3, DB::table('license_keys')->whereNotNull('delivery_id')->count());

        // И проводок выдачи ровно три: выручка не признана дважды.
        self::assertSame(
            3,
            DB::table('ledger_transactions')
                ->where('order_id', $order->id)
                ->where('kind', 'order_delivered')
                ->count(),
        );
    }

    #[Test]
    public function the_money_of_a_recovered_order_still_adds_up(): void
    {
        $order = $this->paidOrder();

        $stuckItem = $this->holdLastItem($order);
        app(DeliverOrder::class)->execute($order->public_id);
        $this->killTheWorker($stuckItem);
        app(DeliverOrder::class)->execute($order->public_id);

        // Инвариант ТЗ 1.3 после восстановления: заказ выдан целиком, значит
        // вся предоплата закрыта выручкой и остатка по нему нет.
        /** @var int|string|null $prepayment */
        $prepayment = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.order_id', $order->id)
            ->where('a.code', 'customer_prepayment')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS balance")
            ->value('balance');

        self::assertSame(0, (int) $prepayment, 'После восстановления предоплата по заказу не закрыта.');
    }

    /**
     * Занять последнюю позицию живой арендой — «её выдаёт другой воркер».
     *
     * Пишется напрямую в базу намеренно: смысл теста в том, чтобы система
     * выбралась из состояния, которое сама создать не может, но которое
     * оставляет после себя убитый процесс.
     *
     * Заметьте, чего здесь НЕТ: выдача не стирается и ключ не возвращается
     * в пул. Первая попытка написать фикстуру именно так провалилась — база
     * не дала снять выдачу с ключа (`gs_forbid_key_reassignment`). И это
     * правильный отказ: выданный ключ вернуть нельзя, он уже у покупателя.
     * Настоящая авария выглядит иначе — она случается ДО фиксации, и позиция
     * остаётся вовсе без выдачи.
     */
    private function holdLastItem(Order $order): int
    {
        $lastItemId = DB::table('order_items')
            ->where('order_id', $order->id)
            ->orderByDesc('line_no')
            ->value('id');

        self::assertIsNumeric($lastItemId);

        DB::table('order_items')->where('id', $lastItemId)->update([
            'status' => OrderItemStatus::Delivering->value,
            'lease_token' => (string) Str::uuid(),
            'lease_owner' => 'busy-worker',
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        return (int) $lastItemId;
    }

    /**
     * Воркер умер: аренда протухла, но не снята, и позиция так и не выдана.
     */
    private function killTheWorker(int $itemId): void
    {
        DB::table('order_items')->where('id', $itemId)->update([
            'lease_expires_at' => now()->subMinutes(5),
        ]);
    }

    private function paidOrder(): Order
    {
        $order = $this->makeMultiOrder(self::SKUS);
        $eventId = 'evt_recover_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
