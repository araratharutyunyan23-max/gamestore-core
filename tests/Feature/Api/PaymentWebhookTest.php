<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Payments\Enums\PaymentEventState;
use App\Jobs\ApplyPaymentEventJob;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Контракт приёма вебхука.
 *
 * Главное требование здесь не функциональное, а поведенческое: эндпоинт обязан
 * отвечать 200 быстро и почти всегда. Любой 4xx/5xx означает, что платёжная
 * система считает доставку неудачной и повторяет её — то есть мы сами
 * порождаем ту самую волну параллельных событий, которую потом обязаны пережить.
 */
final class PaymentWebhookTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Очередь подменяется: здесь проверяется контракт HTTP и содержимое
        // инбокса, а применение платежа — предмет отдельного теста.
        Queue::fake();
    }

    #[Test]
    public function it_accepts_a_webhook_and_stores_it_in_the_inbox(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $event = PaymentEvent::query()->where('event_id', $payload['event_id'])->firstOrFail();

        self::assertSame($order->public_id, $event->order_public_id);
        self::assertSame(129000, $event->amount_minor);
        self::assertSame(PaymentEventState::Pending, $event->process_state);
        self::assertNull($event->applied_at);

        Queue::assertPushed(ApplyPaymentEventJob::class, 1);
    }

    #[Test]
    public function repeating_the_same_event_id_changes_nothing(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        // Критерий приёмки №2: повторный вебхук с тем же event_id ничего
        // не меняет. Гарантия — индекс payment_events_event_id_uq.
        self::assertSame(1, PaymentEvent::query()->count());
    }

    #[Test]
    public function ten_simultaneous_copies_of_one_event_produce_one_row(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);

        for ($i = 0; $i < 10; $i++) {
            $this->postWebhook($payload)->assertOk();
        }

        self::assertSame(1, PaymentEvent::query()->count());
    }

    #[Test]
    public function a_reused_event_id_with_a_different_body_is_not_lost_silently(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order);

        $this->postWebhook($payload)->assertOk();

        // Нарушение контракта на стороне платёжной системы. Отвечаем 200,
        // чтобы не ловить вечные повторы, но факт обязан быть виден.
        $this->postWebhook([...$payload, 'amount' => 999])->assertOk();

        self::assertSame(1, PaymentEvent::query()->count());
    }

    #[Test]
    public function a_malformed_body_still_gets_200(): void
    {
        // 5xx здесь означал бы бесконечные повторы того, что мы всё равно
        // никогда не разберём.
        $this->postWebhook(['nonsense' => true])->assertOk();

        $event = PaymentEvent::query()->first();

        self::assertNotNull($event);
        self::assertSame(PaymentEventState::Malformed, $event->process_state);

        // Разбирать нечего — применять тоже нечего.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_amount_sent_as_a_float_is_treated_as_malformed(): void
    {
        $order = $this->makeOrder();

        $this->postWebhook($this->webhookPayload($order, ['amount' => 1290.5]))->assertOk();

        $event = PaymentEvent::query()->firstOrFail();

        self::assertSame(PaymentEventState::Malformed, $event->process_state);
    }

    #[Test]
    public function a_webhook_that_arrives_before_its_order_is_accepted_anyway(): void
    {
        // Критерий приёмки №3: вебхук может прийти раньше заказа. Это штатный
        // сценарий, а не ошибка, поэтому событие сохраняется как есть.
        $this->postWebhook([
            'event_id' => 'evt_early',
            'order_id' => 'ord_99999',
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => '2026-01-01T12:00:00Z',
        ])->assertOk();

        $event = PaymentEvent::query()->where('event_id', 'evt_early')->firstOrFail();

        self::assertSame(PaymentEventState::Pending, $event->process_state);
        self::assertNull($event->applied_at, 'Осиротевшее событие обязано остаться неприменённым.');
    }

    #[Test]
    public function the_webhook_endpoint_is_not_throttled(): void
    {
        $order = $this->makeOrder();

        // 429 для платёжной системы — такой же признак неудачной доставки,
        // как 500: она просто повторит событие ещё раз.
        for ($i = 0; $i < 30; $i++) {
            $this->postWebhook($this->webhookPayload($order, ['event_id' => "evt_burst_{$i}"]))
                ->assertOk();
        }

        self::assertSame(30, PaymentEvent::query()->count());
    }
}
