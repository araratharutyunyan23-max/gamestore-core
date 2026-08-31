<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Критерии приёмки 4 и 5 — ловушка таймаута и переключение на второго поставщика.
 *
 * Всё идёт через живые заглушки настоящим HTTP. Проверяется не только наше
 * состояние, но и НЕЗАВИСИМЫЙ счётчик со стороны поставщика: сколько кодов
 * он реально выдал. Наши таблицы могут врать, его журнал — нет.
 */
final class TimeoutTrapTest extends SupplierTestCase
{
    use OrderFixtures;

    private const SKU = 'STEAM-TOPUP-500';

    #[Test]
    public function a_timeout_that_actually_issued_a_code_does_not_produce_a_second_one(): void
    {
        // Критерий приёмки №4. Поставщик выдаёт код и списывает его, но ответ
        // не доходит: классическая ловушка. Наивная логика сочтёт это отказом
        // и купит второй код.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->behaviour(SupplierName::A, 'timeout_after_issue', times: 1);

        $order = $this->paidSupplierOrder();

        $outcome = app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(DeliveryOutcome::Delivered, $outcome, 'Код, выданный до таймаута, обязан быть подобран.');

        // Ключевая проверка: у поставщика по этому заказу ровно одна выдача.
        self::assertSame(
            1,
            $this->issuedCount(SupplierName::A, $order->public_id),
            'Поставщик выдал больше одного кода — повтор ушёл с новым request_id.',
        );

        // И ко второму поставщику мы не ходили вовсе.
        self::assertSame(0, $this->issuedCount(SupplierName::B, $order->public_id));

        $this->assertDeliveredOnce($order);

        // Все обращения шли под ОДНИМ request_id: эпоха не росла, потому что
        // отсутствие выдачи доказано не было.
        self::assertSame(
            1,
            DB::table('delivery_attempts')->where('order_id', $order->id)->count(),
            'Появилась вторая попытка — значит был создан второй request_id.',
        );
    }

    #[Test]
    public function an_authoritative_refusal_from_a_fails_over_to_b_and_delivers_once(): void
    {
        // Критерий приёмки №5. У A пусто — это ДОКАЗАННЫЙ отказ, и только он
        // разрешает уход ко второму поставщику.
        $this->stock(SupplierName::A, self::SKU, 0);
        $this->stock(SupplierName::B, self::SKU, 5);

        $order = $this->paidSupplierOrder();

        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($order->public_id));

        self::assertSame(0, $this->issuedCount(SupplierName::A, $order->public_id));
        self::assertSame(1, $this->issuedCount(SupplierName::B, $order->public_id), 'Товар выдан не ровно один раз.');

        $this->assertDeliveredOnce($order);

        // Две попытки: доказанный отказ A и успех B. Эпоха выросла законно —
        // отсутствие выдачи у A доказано конвертом с бизнес-причиной.
        self::assertSame(2, DB::table('delivery_attempts')->where('order_id', $order->id)->count());
        self::assertSame(
            1,
            DB::table('delivery_attempts')->where('order_id', $order->id)->where('definitive', true)->count(),
        );
    }

    #[Test]
    public function an_unreachable_supplier_fails_over_because_the_request_never_left(): void
    {
        // «Поставщик A недоступен» в чистом виде: соединение не устанавливается.
        // Это тоже ДОКАЗАННОЕ отсутствие выдачи — запрос физически не ушёл.
        config(['suppliers.a.url' => 'http://127.0.0.1:9']);
        $this->stock(SupplierName::B, self::SKU, 5);

        $order = $this->paidSupplierOrder();

        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($order->public_id));
        self::assertSame(1, $this->issuedCount(SupplierName::B, $order->public_id));
        $this->assertDeliveredOnce($order);
    }

    #[Test]
    public function a_5xx_from_the_first_supplier_still_reaches_the_second(): void
    {
        // ТЗ прямо говорит: «поставщик A иногда 5xx». Любой 5xx для нас —
        // НЕИЗВЕСТНОСТЬ, а не отказ: 500 мог прилететь от прокси уже после
        // того, как код закоммичен. Поэтому уход к B происходит не сразу,
        // а через выяснение: probe -> печать -> и только тогда B.
        //
        // Пока заглушка не отпускала захват при ошибке, этот путь был
        // заблокирован навсегда: probe вечно отвечал in_flight, печать
        // отвергалась, заказ висел.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 5);
        $this->behaviour(SupplierName::A, 'error', times: 3);

        $order = $this->paidSupplierOrder();

        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($order->public_id));

        self::assertSame(0, $this->issuedCount(SupplierName::A, $order->public_id));
        self::assertSame(1, $this->issuedCount(SupplierName::B, $order->public_id), 'Товар выдан не ровно один раз.');
        $this->assertDeliveredOnce($order);

        // Попытка к A закрыта печатью — доказано, что кода по ней не будет.
        self::assertSame(
            'sealed',
            DB::table('delivery_attempts')->where('order_id', $order->id)->where('supplier', 'A')->value('outcome'),
        );
    }

    #[Test]
    public function when_both_suppliers_refuse_the_order_stays_recoverable(): void
    {
        // «B резервный, тоже может падать» — прямая цитата из ТЗ.
        // Оба отказали: заказ обязан уйти в ВОССТАНОВИМЫЙ отказ, а не упасть
        // и не остаться выданным наполовину.
        $this->stock(SupplierName::A, self::SKU, 0);
        $this->stock(SupplierName::B, self::SKU, 0);

        $order = $this->paidSupplierOrder();

        self::assertSame(DeliveryOutcome::DeliveryFailed, app(DeliverOrder::class)->execute($order->public_id));

        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(0, $this->issuedCount(SupplierName::A, $order->public_id));
        self::assertSame(0, $this->issuedCount(SupplierName::B, $order->public_id));

        $order->refresh();
        self::assertSame(OrderStatus::DeliveryFailed, $order->status);
        self::assertTrue($order->status->isRecoverable(), 'Отказ обоих поставщиков обязан быть восстановимым.');
    }

    #[Test]
    public function a_failed_delivery_recovers_after_the_supplier_comes_back(): void
    {
        // Путь из ТЗ: paid -> delivering -> delivery_failed -> повторная
        // выдача -> delivered. Проверяется целиком, а не по кускам.
        $this->stock(SupplierName::A, self::SKU, 0);
        $this->stock(SupplierName::B, self::SKU, 0);

        $order = $this->paidSupplierOrder();
        app(DeliverOrder::class)->execute($order->public_id);
        self::assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);

        // Поставщик вернулся.
        $this->stock(SupplierName::A, self::SKU, 5);

        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($order->public_id));
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);

        // И ровно одна выдача, несмотря на четыре обращения к поставщикам.
        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
        $this->assertDeliveredOnce($order);

        // Эпоха выросла и СОХРАНИЛАСЬ: повторная выдача пошла с новым
        // request_id. Пока она жила только в памяти, повтор упирался
        // в delivery_attempts_request_uq и заказ было не довести.
        self::assertGreaterThan(1, $order->refresh()->delivery_epoch);
    }

    #[Test]
    public function an_unresolved_timeout_never_moves_to_the_second_supplier(): void
    {
        // Поставщик молчит, кода не выдавал, но ДОКАЗАТЕЛЬСТВ этого у нас нет.
        // Уход ко второму здесь запрещён: если код всё-таки был выдан, клиент
        // получит два, а мы заплатим дважды.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 5);
        // slow дольше и таймаута, и окна ожидания перед probe: к моменту
        // probe запрос у поставщика ещё в работе.
        $this->behaviour(SupplierName::A, 'slow', times: 2, delayMs: 4000);

        $order = $this->paidSupplierOrder();

        $outcome = app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(DeliveryOutcome::AwaitingResolution, $outcome);

        // Ко второму поставщику не ушли.
        self::assertSame(0, $this->issuedCount(SupplierName::B, $order->public_id));
        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());

        // Заказ остаётся в delivering: это не отказ, а незавершённое выяснение.
        self::assertSame(OrderStatus::Delivering, $order->refresh()->status);

        // Попытка одна и она неразрешённая — открыть вторую БД не даст.
        self::assertSame(1, DB::table('delivery_attempts')->where('order_id', $order->id)->count());
        self::assertSame(
            'unknown',
            DB::table('delivery_attempts')->where('order_id', $order->id)->value('outcome'),
        );
    }

    #[Test]
    public function a_repeated_call_after_a_timeout_reuses_the_same_request_id(): void
    {
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->behaviour(SupplierName::A, 'timeout_after_issue', times: 1);

        $order = $this->paidSupplierOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $requestId = DB::table('delivery_attempts')->where('order_id', $order->id)->value('request_id');

        // Идентификатор строится из заказа, поставщика и эпохи — и НЕ зависит
        // от номера сетевой попытки. Именно поэтому повтор безопасен.
        self::assertSame("req_{$order->public_id}-A-1", $requestId);
    }

    private function paidSupplierOrder(): Order
    {
        $order = $this->makeOrder(self::SKU);
        $eventId = 'evt_sup_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }

    private function assertDeliveredOnce(Order $order): void
    {
        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);

        /** @var list<object{total: int|string|null}> $rows */
        $rows = DB::select(
            'SELECT COALESCE(SUM(amount_signed), 0)::bigint AS total FROM ledger_entries WHERE order_id = ?',
            [$order->id],
        );

        self::assertSame(0, (int) $rows[0]->total, 'Журнал по заказу не сошёлся.');
    }
}
