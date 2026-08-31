<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Domain\Payments\Repositories\PaymentEventRepository;
use App\Models\Order;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Регрессия на устаревшее чтение заказа.
 *
 * Дефект был такой: заказ читался ДО транзакции, а решения принимались ВНУТРИ
 * неё по этому же объекту. В окно между чтением и транзакцией успевает пройти
 * поздний failed, чужая выдача или отмена — и товар уходил по заказу, которого
 * в таком состоянии уже нет.
 *
 * Первый и третий тесты падают без исправления — они и есть регрессия.
 * Второй фиксирует требуемое поведение (отзыв платежа после выдачи не сторнирует
 * деньги); он проходил и до правки, и это честно указано здесь, чтобы никто не
 * принял его за доказательство исправления.
 */
final class StaleReadRegressionTest extends TestCase
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
    public function a_worker_holding_the_lease_keeps_everyone_else_out(): void
    {
        $order = $this->paidOrder();

        // Другой воркер уже взял аренду и ведёт выдачу прямо сейчас.
        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Delivering->value,
            'lease_token' => (string) Str::uuid(),
            'lease_owner' => 'other-worker',
            'lease_expires_at' => now()->addMinutes(2),
        ]);

        $outcome = app(DeliverOrder::class)->execute($order->public_id);

        // Взаимное исключение даёт аренда. По статусу это отличить нельзя:
        // переход delivering -> delivering запрещён и в случае «выдачу ведёт
        // другой», и в случае «предыдущий воркер упал».
        self::assertSame(DeliveryOutcome::AlreadyInProgress, $outcome);
        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(0, DB::table('license_keys')->whereNotNull('delivery_id')->count());
    }

    #[Test]
    public function an_expired_lease_lets_the_order_be_recovered(): void
    {
        $order = $this->paidOrder();

        // Воркер упал во время выдачи: статус остался delivering, аренда протухла.
        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Delivering->value,
            'lease_token' => (string) Str::uuid(),
            'lease_owner' => 'dead-worker',
            'lease_expires_at' => now()->subMinute(),
        ]);

        $outcome = app(DeliverOrder::class)->execute($order->public_id);

        // Живучесть: без перехвата протухшей аренды заказ завис бы навсегда —
        // статус промежуточный, задачи нет, и ни один статусный фильтр его
        // уже не увидит.
        self::assertSame(DeliveryOutcome::Delivered, $outcome);
        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);

        // Аренда снята: заказ не остаётся заблокированным после успеха.
        self::assertNull(DB::table('orders')->where('id', $order->id)->value('lease_token'));
    }

    #[Test]
    public function a_late_failure_on_a_delivered_order_does_not_reverse_the_money(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);

        // Отзыв платежа приходит после выдачи. Деньги сторнировать нельзя:
        // товар уже у клиента, и это предмет ручного разбора, а не автоматики.
        $revoked = $this->webhookPayload($order, [
            'event_id' => 'evt_late_failure',
            'status' => 'failed',
            'created_at' => '2026-06-01T12:00:00Z',
        ]);
        $this->postWebhook($revoked)->assertOk();

        $state = app(ApplyPaymentEvent::class)->execute('evt_late_failure');

        self::assertSame(PaymentEventState::IgnoredFinal, $state);
        self::assertSame(0, DB::table('ledger_transactions')->where('kind', 'payment_reversed')->count());
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
        self::assertTrue($order->needs_review);
        self::assertSame('late_payment_failure', $order->review_reason);
    }

    #[Test]
    public function a_losing_processor_does_not_overwrite_an_applied_event(): void
    {
        $order = $this->paidOrder();
        $eventId = 'evt_paid_'.$order->public_id;

        $before = DB::table('payment_events')->where('event_id', $eventId)->first();
        self::assertNotNull($before);

        // Проигравший гонку обработчик пытается пометить уже применённое
        // событие. До исправления он перетирал 'applied' своим состоянием,
        // и сверка потом объявляла выданный заказ неоплаченным.
        $applied = PaymentEvent::query()->where('event_id', $eventId)->firstOrFail();

        app(PaymentEventRepository::class)->markProcessed($applied, PaymentEventState::Stale);

        $after = DB::table('payment_events')->where('event_id', $eventId)->first();
        self::assertNotNull($after);
        self::assertSame('applied', $after->process_state);
    }

    private function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
    {
        $order = $this->makeOrder($sku);
        $eventId = 'evt_paid_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
