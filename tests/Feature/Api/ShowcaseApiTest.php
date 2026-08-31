<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Контракт витрины.
 *
 * Ключевое здесь — стоимость: два запроса на страницу при любом числе товаров.
 * Не «1 + N» и не один с JOIN к самой перезаписываемой таблице системы.
 */
final class ShowcaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function it_returns_the_showcase(): void
    {
        $this->getJson('/api/v1/products?limit=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data' => [['sku', 'name', 'price_minor', 'currency', 'available']], 'next_cursor']);
    }

    #[Test]
    public function a_page_costs_exactly_two_queries_regardless_of_size(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/v1/products?limit=50')->assertOk();

        // Один запрос за товарами по покрывающему индексу, один за остатками
        // по массиву идентификаторов. Ни больше, ни меньше.
        self::assertCount(2, $queries, "Запросов: \n".implode("\n", $queries));
    }

    #[Test]
    public function stock_is_shown_for_pool_products_and_omitted_for_supplier_ones(): void
    {
        $response = $this->getJson('/api/v1/products?type=key&limit=10')->assertOk();

        /** @var list<array{sku: string, available: ?int}> $items */
        $items = $response->json('data');

        foreach ($items as $item) {
            self::assertIsInt($item['available'], "У товара из пула {$item['sku']} нет остатка.");
        }

        $topups = $this->getJson('/api/v1/products?type=topup&limit=10')->assertOk();

        /** @var list<array{sku: string, available: ?int}> $supplierItems */
        $supplierItems = $topups->json('data');

        foreach ($supplierItems as $item) {
            // У товара от поставщика локального остатка нет по определению.
            // Показать выдуманное число означало бы соврать клиенту.
            self::assertNull($item['available'], "У товара от поставщика {$item['sku']} показан остаток.");
        }
    }

    #[Test]
    public function keyset_pagination_walks_the_whole_catalogue_without_gaps_or_repeats(): void
    {
        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->getJson('/api/v1/products?limit=5'.($cursor === null ? '' : '&cursor='.$cursor))
                ->assertOk();

            /** @var list<array{sku: string}> $items */
            $items = $response->json('data');

            foreach ($items as $item) {
                $seen[] = $item['sku'];
            }

            /** @var string|null $cursor */
            $cursor = $response->json('next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 20);

        // Ни пропусков, ни повторов: курсор идёт по паре (цена, id), а не по
        // одной цене — цены не уникальны, и по одной цене страницы склеивались
        // бы или теряли строки на границе.
        self::assertSame(12, count($seen), 'Каталог обойдён не целиком.');
        self::assertSame(count($seen), count(array_unique($seen)), 'Товары повторились между страницами.');
    }

    #[Test]
    public function it_refuses_an_oversized_limit(): void
    {
        // Без потолка один запрос вытянул бы всю витрину и положил базу.
        $this->getJson('/api/v1/products?limit=100000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    #[Test]
    public function a_broken_cursor_does_not_break_the_page(): void
    {
        // Курсор приходит от клиента, значит может быть любым. Битый курсор —
        // это первая страница, а не 500.
        $this->getJson('/api/v1/products?cursor=не-курсор')->assertOk()->assertJsonCount(12, 'data');
    }
}
