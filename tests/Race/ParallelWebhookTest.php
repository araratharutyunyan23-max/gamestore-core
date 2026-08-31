<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\DrainUnappliedPayments;
use App\Models\Order;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Критерии приёмки 1, 2 и 3 — на настоящем HTTP и настоящей конкуренции.
 *
 * Каждый из пятидесяти запросов внутри себя пытается применить платёж и выдать
 * товар: очередь у тестового сервера синхронная. Это не имитация нагрузки,
 * а прямая проверка того, что конкуренция разруливается ограничениями БД.
 */
final class ParallelWebhookTest extends RaceTestCase
{
    use OrderFixtures;

    #[Test]
    public function fifty_parallel_webhooks_with_the_same_event_id_deliver_exactly_once(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order, ['event_id' => 'evt_same_id']);

        $statuses = $this->fireParallel(array_fill(0, self::CONCURRENCY, $payload));

        // Ни одного ответа, кроме 200: любой другой код означает для платёжной
        // системы неудачную доставку и новую волну повторов.
        self::assertSame([200 => self::CONCURRENCY], $statuses);

        // Честный повтор at-least-once: событие одно.
        self::assertSame(1, $this->rowsFor('payment_events', $order));
        $this->assertDeliveredExactlyOnce($order);
    }

    #[Test]
    public function fifty_parallel_webhooks_with_distinct_event_ids_deliver_exactly_once(): void
    {
        // Самый опасный вариант критерия №1. Идемпотентность по event_id здесь
        // бесполезна: событий действительно пятьдесят разных. Спасает только
        // идемпотентность признания оплаты ПО ЗАКАЗУ.
        $order = $this->makeOrder();

        $payloads = [];

        for ($i = 0; $i < self::CONCURRENCY; $i++) {
            $payloads[] = $this->webhookPayload($order, ['event_id' => "evt_distinct_{$i}"]);
        }

        $statuses = $this->fireParallel($payloads);

        self::assertSame([200 => self::CONCURRENCY], $statuses);
        self::assertSame(self::CONCURRENCY, $this->rowsFor('payment_events', $order));
        $this->assertDeliveredExactlyOnce($order);

        // Ровно одно событие признано оплатой.
        self::assertSame(
            1,
            DB::table('payment_events')
                ->where('order_public_id', $order->public_id)
                ->where('process_state', 'applied')
                ->count(),
            'Оплата признана не по одному событию.',
        );

        // И ни одно из пятидесяти не потеряно: у каждого проставлен исход.
        // Конкретный исход остальных (duplicate_paid или stale) зависит от
        // порядка исполнения и гарантией не является — гарантия в том, что
        // молча не исчезло ничего: каждое событие останется видимым сверке.
        self::assertSame(
            0,
            DB::table('payment_events')
                ->where('order_public_id', $order->public_id)
                ->whereNull('applied_at')
                ->count(),
            'Часть событий осталась без исхода — они потеряны для сверки.',
        );
    }

    #[Test]
    public function a_webhook_that_arrives_before_its_order_is_applied_once_the_order_appears(): void
    {
        // Критерий приёмки №3. Заказа ещё нет: событие приходит на
        // идентификатор, который только предстоит выдать.
        $publicId = 'ord_99001';

        $accepted = Http::acceptJson()->post($this->baseUrl().'/api/v1/webhooks/payment', [
            'event_id' => 'evt_before_order',
            'order_id' => $publicId,
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => '2026-01-01T12:00:00Z',
        ]);

        self::assertSame(200, $accepted->status());

        self::assertNull(
            DB::table('payment_events')->where('event_id', 'evt_before_order')->value('applied_at'),
            'Осиротевшее событие обязано остаться неприменённым, а не потеряться.',
        );

        // Заказ создаётся ПОД ЭТИМ ЖЕ идентификатором.
        DB::table('orders')->insert([
            'public_id' => $publicId,
            'idempotency_key' => 'orphan-key',
            'product_id' => DB::table('products')->where('sku', 'KEY-CS2-PRIME')->value('id'),
            'sku' => 'KEY-CS2-PRIME',
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Created->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Доводка забирает работу из ИНБОКСА, а не из статусов заказов:
        // заказ в created не виден ни одному статусному фильтру.
        DB::table('payment_events')->where('event_id', 'evt_before_order')
            ->update(['received_at' => now()->subMinute()]);

        // Доводка ставит задачу, задача применяет платёж и запускает выдачу —
        // проверяется весь путь восстановления целиком, а не отдельное звено.
        $redispatched = app(DrainUnappliedPayments::class)->execute();

        self::assertSame(1, $redispatched, 'Доводка не подобрала осиротевшее событие.');

        /** @var Order $order */
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();

        self::assertSame(OrderStatus::Delivered, $order->status);
        $this->assertDeliveredExactlyOnce($order);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<int, int> код ответа => сколько раз встретился
     */
    private function fireParallel(array $payloads): array
    {
        $url = $this->baseUrl().'/api/v1/webhooks/payment';

        /** @var array<int, Response> $responses */
        $responses = Http::pool(static fn (Pool $pool): array => array_map(
            static fn (array $payload) => $pool->acceptJson()->timeout(30)->post($url, $payload),
            $payloads,
        ));

        $statuses = array_map(static fn (Response $r): int => $r->status(), array_values($responses));

        return array_count_values($statuses);
    }

    private function assertDeliveredExactlyOnce(Order $order): void
    {
        // Ассерты скоуплены по заказу, а не глобально: глобальный счёт развалится
        // от соседнего теста, оставившего данные.
        self::assertSame(1, $this->rowsFor('deliveries', $order), 'Выдач должно быть ровно одна.');

        self::assertSame(
            1,
            DB::table('ledger_transactions')->where('order_id', $order->id)->where('kind', 'payment_captured')->count(),
            'Оплата признана больше одного раза.',
        );

        self::assertSame(
            1,
            DB::table('ledger_transactions')->where('order_id', $order->id)->where('kind', 'order_delivered')->count(),
            'Выручка признана больше одного раза.',
        );

        self::assertSame(
            1,
            DB::table('license_keys')->whereNotNull('delivery_id')->count(),
            'Из пула ушёл не один ключ.',
        );

        /** @var list<object{total: int|string|null}> $rows */
        $rows = DB::select(
            'SELECT COALESCE(SUM(amount_signed), 0)::bigint AS total FROM ledger_entries WHERE order_id = ?',
            [$order->id],
        );

        self::assertSame(0, (int) $rows[0]->total, 'Журнал по заказу не сошёлся.');
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
    }

    private function rowsFor(string $table, Order $order): int
    {
        $column = $table === 'payment_events' ? 'order_public_id' : 'order_id';
        $value = $table === 'payment_events' ? $order->public_id : $order->id;

        return DB::table($table)->where($column, $value)->count();
    }
}
