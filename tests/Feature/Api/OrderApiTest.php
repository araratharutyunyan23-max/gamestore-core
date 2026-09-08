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
    public function it_refuses_an_overlong_idempotency_key_with_422_not_500(): void
    {
        // Колонка idempotency_key — varchar(128). Без проверки длины запрос
        // доходил бы до базы и падал там, превращая ошибку клиента в 500.
        $this->withHeader('Idempotency-Key', str_repeat('k', 200))
            ->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])
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
    public function reading_an_order_costs_the_same_regardless_of_how_many_items_it_has(): void
    {
        // Раньше здесь стояло «не больше четырёх запросов». Константа проверяет
        // не то: она ловит момент, когда запросов стало больше, но ничего не
        // говорит о том, растёт ли их число ВМЕСТЕ С ЗАКАЗОМ. А N+1 — это
        // именно рост, и со второго этапа расти есть чему: позиций в заказе
        // теперь много, и у каждой свой товар.
        $one = $this->countQueriesReadingOrder(['KEY-EFT']);
        $many = $this->countQueriesReadingOrder([
            'KEY-CS2-PRIME', 'KEY-EFT', 'STEAM-TOPUP-500', 'SUB-DISCORD-1M', 'SUB-YT-3M',
        ]);

        self::assertSame(
            $one,
            $many,
            "Заказ из одной позиции стоил {$one} запросов, из пяти — {$many}. Это N+1.",
        );

        // И заодно нижняя граница разумности: связи подгружаются заранее,
        // поэтому чтение укладывается в единицы запросов, а не в десятки.
        self::assertLessThanOrEqual(8, $many, "Чтение заказа стоило {$many} запросов.");
    }

    /**
     * @param  non-empty-list<string>  $skus
     */
    private function countQueriesReadingOrder(array $skus): int
    {
        $created = $this->withHeader('Idempotency-Key', 'key-nplus1-'.count($skus))
            ->postJson('/api/v1/orders', [
                'items' => array_map(static fn (string $sku): array => ['sku' => $sku], $skus),
            ])->assertCreated();

        // Идентификатор берётся из ответа, а не пишется константой: последова-
        // тельность в PostgreSQL не откатывается вместе с тестовой транзакцией,
        // поэтому номер заказа меняется от прогона к прогону.
        /** @var string $publicId */
        $publicId = $created->json('data.id');

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->getJson("/api/v1/orders/{$publicId}")->assertOk();

        return $queries;
    }
}
