<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\LicenseKey;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Выдача из собственного пула ключей.
 *
 * Все проверки строятся вокруг одного вопроса: сколько ключей ушло клиенту.
 * Ответ обязан быть «ровно один» в любом сценарии, включая повтор, отзыв
 * платежа и пустой остаток.
 */
final class PoolDeliveryTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    #[Test]
    public function it_delivers_a_key_and_closes_the_money(): void
    {
        $order = $this->paidOrder();

        self::assertSame(DeliveryOutcome::Delivered, $this->deliver($order));

        $order->refresh()->load('delivery');
        self::assertSame(OrderStatus::Delivered, $order->status);
        self::assertNotNull($order->delivered_at);

        $delivery = $order->delivery;
        self::assertNotNull($delivery);

        // Код в ответе — расшифрованный, а в базе лежит шифртекст.
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $delivery->code_encrypted);

        $key = LicenseKey::query()->findOrFail($delivery->license_key_id);
        self::assertSame(LicenseKeyStatus::Issued, $key->status);
        self::assertSame($delivery->id, $key->delivery_id);

        // Обязательство перед клиентом закрыто тем же движением, которым
        // признана выручка.
        self::assertSame(0, $this->accountBalance('customer_prepayment'));
        self::assertSame(-129000, $this->accountBalance('revenue'));
        self::assertSame(0, $this->ledgerImbalance());
    }

    #[Test]
    public function delivery_updates_the_stock_counters(): void
    {
        $order = $this->paidOrder();
        $before = $this->stock($order);

        $this->deliver($order);

        $after = $this->stock($order);

        self::assertSame($before['available'] - 1, $after['available']);
        self::assertSame($before['issued'] + 1, $after['issued']);
        // Резерв закрывается тем же путём, что и открывается: висящий резерв
        // означал бы утечку ключа из оборота.
        self::assertSame($before['reserved'], $after['reserved']);
    }

    #[Test]
    public function delivering_the_same_order_twice_hands_out_only_one_key(): void
    {
        $order = $this->paidOrder();

        self::assertSame(DeliveryOutcome::Delivered, $this->deliver($order));
        self::assertSame(DeliveryOutcome::AlreadyDelivered, $this->deliver($order));

        // Гарантия — индекс deliveries_order_uq, а не проверка в коде.
        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(1, LicenseKey::query()->where('delivery_id', '!=', null)->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('kind', 'order_delivered')->count());
    }

    #[Test]
    public function a_repeat_delivery_does_not_downgrade_a_delivered_order(): void
    {
        $order = $this->paidOrder();
        $this->deliver($order);
        $this->deliver($order);

        // Перевод уже выданного заказа в delivery_failed — самая дорогая ошибка
        // восстановления: подметальщик пошёл бы выдавать второй раз.
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
    }

    #[Test]
    public function an_empty_pool_leaves_the_order_recoverable_instead_of_failing(): void
    {
        // KEY-EFT засеян дефицитным намеренно: два ключа, третий заказ упирается
        // в пустой остаток. Это критерий приёмки №6.
        $first = $this->paidOrder('KEY-EFT');
        $second = $this->paidOrder('KEY-EFT');
        $third = $this->paidOrder('KEY-EFT');

        self::assertSame(DeliveryOutcome::Delivered, $this->deliver($first));
        self::assertSame(DeliveryOutcome::Delivered, $this->deliver($second));
        self::assertSame(DeliveryOutcome::OutOfStock, $this->deliver($third));

        $third->refresh();
        self::assertSame(OrderStatus::OutOfStock, $third->status);
        self::assertTrue($third->status->isRecoverable(), 'Пустой остаток обязан быть восстановимым состоянием.');
        self::assertNull($third->delivery);
    }

    #[Test]
    public function an_order_recovers_after_the_pool_is_refilled(): void
    {
        $first = $this->paidOrder('KEY-EFT');
        $second = $this->paidOrder('KEY-EFT');
        $stuck = $this->paidOrder('KEY-EFT');

        $this->deliver($first);
        $this->deliver($second);
        $this->deliver($stuck);

        self::assertSame(OrderStatus::OutOfStock, $stuck->refresh()->status);

        $this->refillPool($stuck, 'ZZZZ-ZZZZ-Z001');

        // Повторная выдача из восстановимого состояния доводит заказ до конца
        // и не создаёт второй выдачи.
        self::assertSame(DeliveryOutcome::Delivered, $this->deliver($stuck));
        self::assertSame(OrderStatus::Delivered, $stuck->refresh()->status);
        self::assertSame(1, DB::table('deliveries')->where('order_id', $stuck->id)->count());
    }

    #[Test]
    public function a_revoked_payment_blocks_delivery_and_raises_a_finding(): void
    {
        $order = $this->paidOrder();

        // Отзыв платежа приходит между признанием оплаты и стартом выдачи.
        // Статус заказа при этом остаётся paid, поэтому проверка по статусу
        // такое не поймает — ловит только чтение проекции платежа.
        DB::table('order_payment_states')->where('order_id', $order->id)->update(['state' => 'failed']);

        self::assertSame(DeliveryOutcome::PaymentNotConfirmed, $this->deliver($order));

        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertTrue($order->refresh()->needs_review);
        self::assertSame(1, DB::table('reconciliation_findings')
            ->where('kind', 'payment_revoked')
            ->where('subject_ref', $order->public_id)
            ->count());
    }

    #[Test]
    public function an_unpaid_order_is_never_delivered(): void
    {
        $order = $this->makeOrder();

        self::assertSame(DeliveryOutcome::NotDeliverable, $this->deliver($order));
        self::assertSame(0, DB::table('deliveries')->count());
    }

    #[Test]
    public function a_supplier_backed_product_is_not_delivered_from_the_pool(): void
    {
        // У товара от поставщика нет локального пула: сетевой путь с ловушкой
        // таймаута появляется на шаге Ш4, и до тех пор выдача не выполняется.
        $order = $this->paidOrder('STEAM-TOPUP-500');

        self::assertSame(DeliveryOutcome::SupplierNotImplemented, $this->deliver($order));
        self::assertSame(0, DB::table('deliveries')->count());
    }

    private function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
    {
        $order = $this->makeOrder($sku);
        $payload = $this->webhookPayload($order, ['event_id' => 'evt_paid_'.$order->public_id]);

        $this->postWebhook($payload)->assertOk();
        app(ApplyPaymentEvent::class)->execute('evt_paid_'.$order->public_id);

        return $order->refresh();
    }

    private function deliver(Order $order): DeliveryOutcome
    {
        return app(DeliverOrder::class)->execute($order->public_id);
    }

    private function refillPool(Order $order, string $code): void
    {
        // Пополнение идёт единой операцией «ключи + счётчик»: раздельная вставка
        // занижает остаток, и восстановление начинает падать на CHECK.
        DB::table('license_keys')->insert([
            'product_id' => $order->product_id,
            'code_encrypted' => encrypt($code),
            'code_hash' => LicenseKey::fingerprint($code),
            'code_last4' => LicenseKey::last4($code),
            'status' => LicenseKeyStatus::Available->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_stock')->where('product_id', $order->product_id)->update([
            'available_count' => DB::raw('available_count + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{available: int, reserved: int, issued: int}
     */
    private function stock(Order $order): array
    {
        /** @var list<object{available_count: int, reserved_count: int, issued_count: int}> $rows */
        $rows = DB::select(
            'SELECT available_count, reserved_count, issued_count FROM product_stock WHERE product_id = ?',
            [$order->product_id],
        );

        return [
            'available' => (int) $rows[0]->available_count,
            'reserved' => (int) $rows[0]->reserved_count,
            'issued' => (int) $rows[0]->issued_count,
        ];
    }

    private function accountBalance(string $account): int
    {
        /** @var list<object{total: int|string|null}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT COALESCE(SUM(e.amount_signed), 0)::bigint AS total
              FROM ledger_entries e
              JOIN ledger_accounts a ON a.id = e.account_id
             WHERE a.code = ?
        SQL, [$account]);

        return (int) $rows[0]->total;
    }

    private function ledgerImbalance(): int
    {
        /** @var list<object{total: int|string|null}> $rows */
        $rows = DB::select('SELECT COALESCE(SUM(amount_signed), 0)::bigint AS total FROM ledger_entries');

        return (int) $rows[0]->total;
    }
}
