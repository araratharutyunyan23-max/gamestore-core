<?php

declare(strict_types=1);

namespace Tests\Feature\History;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * ТЗ 4: восстановление картины на любой момент.
 *
 * Заказ проживает полный путь, и на три разных момента отдаются три разных
 * состояния. Главное свойство здесь — ответ о прошлом НЕ ЗАВИСИТ от того,
 * что случилось после: заказ давно выдан, а вопрос «в каком состоянии он был
 * до оплаты» обязан отвечать «создан», а не «выдан».
 */
final class PointInTimeTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
        config()->set('ops.token', 'ops-secret');
    }

    #[Test]
    public function three_moments_of_one_order_give_three_different_states(): void
    {
        $created = CarbonImmutable::parse('2026-03-01T10:00:00Z');

        $this->travelTo($created);
        $order = $this->makeMultiOrder(['KEY-CS2-PRIME', 'KEY-GTA5']);

        $this->travelTo($created->addHour());
        $this->payFor($order);

        $this->travelTo($created->addHours(2));
        app(DeliverOrder::class)->execute($order->public_id);

        $this->travelBack();

        // До создания заказа не было вовсе. Это ответ, а не ошибка.
        $this->asOf($order, $created->subMinute())
            ->assertOk()
            ->assertJsonPath('data.existed', false);

        // Между созданием и оплатой — создан, ничего не выдано.
        $this->asOf($order, $created->addMinutes(30))
            ->assertOk()
            ->assertJsonPath('data.existed', true)
            ->assertJsonPath('data.status', OrderStatus::Created->value)
            ->assertJsonPath('data.delivered_items', 0)
            ->assertJsonPath('data.items.0.status', OrderItemStatus::Pending->value);

        // После оплаты, но до выдачи — оплачен, по-прежнему ничего не выдано.
        $this->asOf($order, $created->addMinutes(90))
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Paid->value)
            ->assertJsonPath('data.delivered_items', 0);

        // После выдачи — выдан, обе позиции закрыты.
        $this->asOf($order, $created->addHours(3))
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Delivered->value)
            ->assertJsonPath('data.delivered_items', 2)
            ->assertJsonPath('data.items.0.status', OrderItemStatus::Delivered->value)
            ->assertJsonPath('data.items.1.status', OrderItemStatus::Delivered->value);
    }

    #[Test]
    public function the_past_does_not_change_when_the_present_does(): void
    {
        // Свойство, ради которого всё это и делается. Заказ уже выдан; ответ
        // о моменте до выдачи обязан остаться прежним. Иначе «состояние
        // на дату» показывает сегодняшнюю правду с прошлогодней датой.
        $created = CarbonImmutable::parse('2026-03-05T08:00:00Z');

        $this->travelTo($created);
        $order = $this->makeMultiOrder(['KEY-CS2-PRIME']);
        $this->travelTo($created->addMinutes(10));
        $this->payFor($order);
        $this->travelBack();

        $beforeDelivery = $this->asOf($order, $created->addMinutes(20))->json('data');

        $this->travelTo($created->addHour());
        app(DeliverOrder::class)->execute($order->public_id);
        $this->travelBack();

        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);

        // Тот же вопрос, заданный после выдачи, обязан дать тот же ответ.
        self::assertSame($beforeDelivery, $this->asOf($order, $created->addMinutes(20))->json('data'));
    }

    #[Test]
    public function a_future_moment_is_refused_rather_than_guessed(): void
    {
        // «Состояние заказа завтра» — не вопрос о фактах. Ответить на него
        // текущим состоянием значит выдать сегодняшнюю правду за завтрашнюю.
        $order = $this->makeMultiOrder(['KEY-CS2-PRIME']);

        $this->asOf($order, CarbonImmutable::now()->addDay())->assertStatus(422);
    }

    #[Test]
    public function period_totals_add_up_to_the_closing_balance(): void
    {
        // ТЗ 4.3: итоги за период считаются из истории и сходятся.
        $start = CarbonImmutable::parse('2026-03-10T00:00:00Z');

        $this->travelTo($start->addHour());
        $first = $this->makeMultiOrder(['KEY-CS2-PRIME']);
        $this->payFor($first);
        app(DeliverOrder::class)->execute($first->public_id);

        $this->travelTo($start->addHours(5));
        $second = $this->makeMultiOrder(['KEY-GTA5']);
        $this->payFor($second);
        app(DeliverOrder::class)->execute($second->public_id);

        $this->travelBack();

        // urlencode обязателен: ISO-8601 содержит «+» в смещении часового
        // пояса, а в query-строке «+» означает пробел. Без кодирования
        // 2026-03-10T00:00:00+00:00 приезжает как «...00:00 00:00» и
        // отбивается валидацией — что, кстати, правильнее, чем угадывать.
        $body = $this->ops(
            '/ops/ledger?from='.urlencode($start->toIso8601String())
            .'&to='.urlencode($start->addHours(6)->toIso8601String()),
        )
            ->assertOk()
            ->json('data');

        self::assertIsArray($body);

        /** @var array<string, int> $opening */
        $opening = $body['opening'];
        /** @var array<string, int> $turnover */
        $turnover = $body['turnover'];
        /** @var array<string, int> $closing */
        $closing = $body['closing'];

        // Остаток на начало плюс оборот равен остатку на конец — по каждому
        // счёту. Это и есть «итоги сходятся»: если бы обороты считались
        // из другого источника, равенство было бы совпадением.
        foreach ($closing as $account => $balance) {
            self::assertSame(
                $balance,
                ($opening[$account] ?? 0) + ($turnover[$account] ?? 0),
                "По счёту {$account} остаток на начало плюс оборот не равен остатку на конец.",
            );
        }

        self::assertNotSame([], $turnover, 'Оборот за период пуст — проверять нечего.');
    }

    #[Test]
    public function balances_of_the_past_ignore_later_movements(): void
    {
        $start = CarbonImmutable::parse('2026-03-15T00:00:00Z');

        $this->travelTo($start->addHour());
        $order = $this->makeMultiOrder(['KEY-CS2-PRIME']);
        $this->payFor($order);
        $this->travelBack();

        // На момент до создания заказа денег по нему быть не могло.
        $before = $this->ops('/ops/ledger?as_of='.urlencode($start->toIso8601String()))
            ->assertOk()
            ->json('data.balances');

        self::assertSame([], $before, 'В прошлом видны движения, которых тогда ещё не было.');

        $after = $this->ops('/ops/ledger?as_of='.urlencode($start->addHours(2)->toIso8601String()))
            ->assertOk()
            ->json('data.balances');

        self::assertIsArray($after);
        self::assertArrayHasKey('customer_prepayment', $after);
    }

    #[Test]
    public function the_ledger_history_is_closed_by_the_ops_token(): void
    {
        $this->get('/ops/ledger')->assertForbidden();
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function asOf(Order $order, CarbonImmutable $moment): TestResponse
    {
        return $this->getJson("/api/v1/orders/{$order->public_id}?as_of=".urlencode($moment->toIso8601String()));
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function ops(string $uri): TestResponse
    {
        return $this->getJson($uri, ['X-Ops-Token' => 'ops-secret']);
    }

    private function payFor(Order $order): void
    {
        $eventId = 'evt_pit_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);
    }
}
