<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Применение платежа: деньги, статус и проекция.
 *
 * Здесь проверяется то, что стоит дороже всего при ошибке — двойное признание
 * оплаты и потеря факта отзыва платежа.
 */
final class ApplyPaymentEventTest extends TestCase
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
    public function a_paid_event_marks_the_order_paid_and_records_the_money(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);
        $this->postWebhook($payload)->assertOk();

        $state = $this->apply($payload['event_id']);

        self::assertSame(PaymentEventState::Applied, $state);

        $order->refresh();
        self::assertSame(OrderStatus::Paid, $order->status);
        self::assertNotNull($order->paid_at);
        self::assertSame(PaymentProjectionState::Paid, $order->paymentState?->state);

        self::assertSame(1, $this->ledgerTransactionCount('payment_captured'));
        self::assertSame(0, $this->ledgerImbalance());
        // Оплачено, но ещё не выдано: обязательство перед клиентом открыто.
        self::assertSame(-129000, $this->accountBalance('customer_prepayment'));
    }

    #[Test]
    public function applying_the_same_event_twice_changes_nothing(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);
        $this->postWebhook($payload)->assertOk();

        $this->apply($payload['event_id']);
        $second = $this->apply($payload['event_id']);

        self::assertSame(PaymentEventState::Applied, $second);
        self::assertSame(1, $this->ledgerTransactionCount('payment_captured'));
        self::assertSame(1, $this->transitionCount($order, 'paid'));
    }

    #[Test]
    public function fifty_distinct_event_ids_recognise_the_payment_exactly_once(): void
    {
        // Это самый опасный вариант критерия приёмки №1: платёжная система
        // прислала 50 РАЗНЫХ event_id по одному заказу. Идемпотентность по
        // событию здесь не спасает — только идемпотентность по ЗАКАЗУ.
        $order = $this->makeOrder();

        for ($i = 0; $i < 50; $i++) {
            $payload = $this->webhookPayload($order, ['event_id' => "evt_distinct_{$i}"]);
            $this->postWebhook($payload)->assertOk();
            $this->apply($payload['event_id']);
        }

        self::assertSame(1, $this->ledgerTransactionCount('payment_captured'), 'Деньги признаны больше одного раза.');
        self::assertSame(-129000, $this->accountBalance('customer_prepayment'));
        self::assertSame(0, $this->ledgerImbalance());
        self::assertSame(1, $this->transitionCount($order, 'paid'));

        // Остальные 49 обязаны быть помечены, а не потеряны: деньги могли
        // реально прийти дважды, и это предмет разбора, а не тишины.
        self::assertSame(49, DB::table('payment_events')->where('process_state', 'duplicate_paid')->count());
    }

    #[Test]
    public function a_failed_event_before_payment_closes_the_order(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order, ['status' => 'failed']);
        $this->postWebhook($payload)->assertOk();

        $this->apply($payload['event_id']);

        self::assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
        self::assertSame(0, $this->ledgerTransactionCount('payment_captured'));
    }

    #[Test]
    public function a_revoked_payment_before_delivery_cancels_the_order_and_reverses_the_money(): void
    {
        $order = $this->makeOrder();

        $paid = $this->webhookPayload($order, ['event_id' => 'evt_paid', 'created_at' => '2026-01-01T12:00:00Z']);
        $this->postWebhook($paid)->assertOk();
        $this->apply('evt_paid');

        // Отзыв платежа приходит ПОЗЖЕ признания, но выдача ещё не начиналась.
        $revoked = $this->webhookPayload($order, [
            'event_id' => 'evt_revoked',
            'status' => 'failed',
            'created_at' => '2026-01-01T12:05:00Z',
        ]);
        $this->postWebhook($revoked)->assertOk();
        $this->apply('evt_revoked');

        $order->refresh();
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame(PaymentProjectionState::Failed, $order->paymentState?->state);

        // Сторно обязано вернуть обязательство в ноль, иначе журнал будет
        // показывать долг перед клиентом, которого больше нет.
        self::assertSame(0, $this->accountBalance('customer_prepayment'));
        self::assertSame(0, $this->ledgerImbalance());
        self::assertSame(1, $this->ledgerTransactionCount('payment_reversed'));
    }

    #[Test]
    public function an_out_of_order_event_does_not_rewind_the_payment_state(): void
    {
        $order = $this->makeOrder();

        $late = $this->webhookPayload($order, ['event_id' => 'evt_new', 'created_at' => '2026-01-01T12:10:00Z']);
        $this->postWebhook($late)->assertOk();
        $this->apply('evt_new');

        // Устаревшее событие приходит ПОСЛЕ более нового. Решает метка времени
        // события, а не порядок доставки.
        $stale = $this->webhookPayload($order, [
            'event_id' => 'evt_old',
            'status' => 'failed',
            'created_at' => '2026-01-01T12:00:00Z',
        ]);
        $this->postWebhook($stale)->assertOk();

        self::assertSame(PaymentEventState::Stale, $this->apply('evt_old'));

        $order->refresh();
        self::assertSame(PaymentProjectionState::Paid, $order->paymentState?->state);
        self::assertSame(OrderStatus::Paid, $order->status);
    }

    #[Test]
    public function an_amount_mismatch_does_not_recognise_the_payment(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order, ['amount' => 1]);
        $this->postWebhook($payload)->assertOk();

        self::assertSame(PaymentEventState::AmountMismatch, $this->apply($payload['event_id']));

        self::assertSame(OrderStatus::Created, $order->refresh()->status);
        self::assertSame(0, $this->ledgerTransactionCount('payment_captured'));
    }

    #[Test]
    public function an_event_for_a_missing_order_stays_unapplied(): void
    {
        $this->postWebhook([
            'event_id' => 'evt_orphan',
            'order_id' => 'ord_99999',
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => '2026-01-01T12:00:00Z',
        ])->assertOk();

        self::assertSame(PaymentEventState::OrderMissing, $this->apply('evt_orphan'));

        // Событие остаётся неприменённым: именно по этому признаку его позже
        // подберёт восстановление, а не по статусу несуществующего заказа.
        self::assertNull(DB::table('payment_events')->where('event_id', 'evt_orphan')->value('applied_at'));
    }

    private function apply(mixed $eventId): PaymentEventState
    {
        self::assertIsString($eventId);

        return app(ApplyPaymentEvent::class)->execute($eventId);
    }

    private function ledgerTransactionCount(string $kind): int
    {
        return DB::table('ledger_transactions')->where('kind', $kind)->count();
    }

    private function transitionCount(Order $order, string $toStatus): int
    {
        return DB::table('order_status_transitions')
            ->where('order_id', $order->id)
            ->where('to_status', $toStatus)
            ->count();
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

        // Каст обязателен: SUM(bigint) в PostgreSQL — numeric, а PDO отдаёт его строкой.
        return (int) $rows[0]->total;
    }

    private function ledgerImbalance(): int
    {
        /** @var list<object{total: int|string|null}> $rows */
        $rows = DB::select('SELECT COALESCE(SUM(amount_signed), 0)::bigint AS total FROM ledger_entries');

        return (int) $rows[0]->total;
    }
}
