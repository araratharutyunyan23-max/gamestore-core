<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Контракт API заказов.
 *
 * Отдельно проверяется, что POST и GET отдают ОДНУ И ТУ ЖЕ форму: разная форма
 * ответа для одного ресурса — классический источник расхождения контракта,
 * который всплывает уже у потребителя.
 */
final class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function it_creates_an_order_by_sku(): void
    {
        $response = $this->withHeader('Idempotency-Key', 'key-1')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.sku', 'KEY-CS2-PRIME')
            // Цена берётся снимком из каталога, а не приходит от клиента.
            ->assertJsonPath('data.amount_minor', 129000)
            ->assertJsonPath('data.currency', 'RUB')
            ->assertJsonPath('data.delivery', null);

        /** @var string $publicId */
        $publicId = $response->json('data.id');

        // Наружу отдаётся только внешний идентификатор из контракта.
        self::assertMatchesRegularExpression('/^ord_\d{5}$/', $publicId);
    }

    #[Test]
    public function repeating_the_same_idempotency_key_returns_the_same_order(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'key-repeat')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME']);

        $second = $this->withHeader('Idempotency-Key', 'key-repeat')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME']);

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, Order::query()->count(), 'Повтор создал второй заказ.');
    }

    #[Test]
    public function a_different_idempotency_key_creates_a_separate_order(): void
    {
        $this->withHeader('Idempotency-Key', 'key-a')->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5']);
        $this->withHeader('Idempotency-Key', 'key-b')->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5']);

        self::assertSame(2, Order::query()->count());
    }

    #[Test]
    public function it_refuses_to_create_an_order_without_an_idempotency_key(): void
    {
        // Сгенерировать ключ на сервере нельзя: он был бы уникален на каждый
        // запрос, то есть повтор создал бы второй заказ — ровно то, от чего
        // заголовок и защищает.
        $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('Idempotency-Key');

        self::assertSame(0, Order::query()->count());
    }

    #[Test]
    public function it_refuses_an_unknown_sku(): void
    {
        $this->withHeader('Idempotency-Key', 'key-unknown')
            ->postJson('/api/v1/orders', ['sku' => 'NO-SUCH-SKU'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'sku_not_purchasable');

        self::assertSame(0, Order::query()->count());
    }

    #[Test]
    public function it_refuses_a_request_without_a_sku(): void
    {
        $this->withHeader('Idempotency-Key', 'key-nosku')
            ->postJson('/api/v1/orders', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function it_returns_an_order_in_the_same_shape_as_creation(): void
    {
        $created = $this->withHeader('Idempotency-Key', 'key-shape')
            ->postJson('/api/v1/orders', ['sku' => 'GIFT-PSN-1000']);

        /** @var string $publicId */
        $publicId = $created->json('data.id');

        $fetched = $this->getJson("/api/v1/orders/{$publicId}");

        $fetched->assertOk();
        self::assertSame($created->json('data'), $fetched->json('data'));
    }

    #[Test]
    public function it_returns_404_for_an_unknown_order(): void
    {
        $this->getJson('/api/v1/orders/ord_99999')->assertNotFound();
    }

    #[Test]
    public function reading_an_order_costs_a_constant_number_of_queries(): void
    {
        $created = $this->withHeader('Idempotency-Key', 'key-nplus1')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-EFT']);

        /** @var string $publicId */
        $publicId = $created->json('data.id');

        // Идентификатор берётся из ответа, а не пишется константой: последова-
        // тельность в PostgreSQL не откатывается вместе с тестовой транзакцией,
        // поэтому номер заказа меняется от прогона к прогону.
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->getJson("/api/v1/orders/{$publicId}")->assertOk();

        // Товар, выдача и состояние оплаты подгружаются заранее: чтение заказа
        // не имеет права стоить N запросов (CLAUDE.md §4).
        self::assertLessThanOrEqual(4, $queries, "Чтение заказа стоило {$queries} запросов.");
    }
}
