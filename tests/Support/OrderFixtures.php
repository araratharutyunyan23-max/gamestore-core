<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ordering\Actions\CreateOrder;
use App\Domain\Ordering\DTO\CreateOrderCommand;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

trait OrderFixtures
{
    private int $fixtureSequence = 0;

    private function makeOrder(string $sku = 'KEY-CS2-PRIME'): Order
    {
        $this->fixtureSequence++;

        return app(CreateOrder::class)->execute(
            new CreateOrderCommand($sku, "fixture-key-{$this->fixtureSequence}"),
        );
    }

    /**
     * Тело вебхука ровно по контракту задания.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function webhookPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt_'.bin2hex((string) $this->fixtureSequence.'01'),
            'order_id' => $order->public_id,
            'status' => 'paid',
            // Контракт оперирует мажорными единицами (CLAUDE.md §10.2).
            'amount' => intdiv($order->amount_minor, 100),
            'currency' => $order->currency,
            'created_at' => Carbon::parse('2026-01-01T12:00:00Z')->toIso8601String(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<JsonResponse>
     */
    private function postWebhook(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/payment', $payload);
    }
}
