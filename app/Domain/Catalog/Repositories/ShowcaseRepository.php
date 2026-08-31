<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Repositories;

use App\Domain\Catalog\DTO\ShowcaseCursor;
use App\Domain\Catalog\DTO\ShowcaseItem;
use App\Domain\Catalog\DTO\ShowcasePage;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\SupplyMode;
use Illuminate\Database\ConnectionInterface;

/**
 * Горячий запрос витрины.
 *
 * Ровно два запроса на страницу при любом числе товаров — не «1 + N» и не один
 * с JOIN. Почему именно так:
 *
 * Товары читаются по покрывающему индексу
 * `(type, price_minor, id) INCLUDE (sku, name, currency) WHERE is_active AND in_stock`.
 * Все нужные колонки лежат в самом индексе, поэтому база не ходит в основную
 * таблицу вообще — Index Only Scan с нулём обращений к куче.
 *
 * Остатки НЕ присоединяются к этому запросу. product_stock — самая
 * перезаписываемая таблица в системе: её страницы почти всегда холодные, и
 * JOIN внутри запроса с LIMIT ломает Index Only Scan, превращая пять
 * прочитанных страниц в сотню. Остатки берутся вторым запросом по массиву
 * идентификаторов уже отобранной страницы.
 *
 * Порядок и курсор совпадают с префиксом индекса — иначе базе пришлось бы
 * сортировать результат, и весь смысл индекса терялся бы.
 */
final readonly class ShowcaseRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function page(?ProductType $type, ?ShowcaseCursor $cursor, int $limit): ShowcasePage
    {
        $rows = $this->products($type, $cursor, $limit + 1);

        // Запрашивается на один больше, чем нужно: наличие лишней строки —
        // это и есть ответ на вопрос «есть ли следующая страница», без
        // отдельного COUNT по всей выборке.
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $stock = $this->availableCounts(array_map(static fn (object $r): int => (int) $r->id, $rows));

        $items = array_map(
            static function (object $row) use ($stock): ShowcaseItem {
                $mode = SupplyMode::from($row->supply_mode);

                return new ShowcaseItem(
                    sku: $row->sku,
                    name: $row->name,
                    priceMinor: (int) $row->price_minor,
                    currency: $row->currency,
                    supplyMode: $mode,
                    availableCount: $mode === SupplyMode::Pool ? ($stock[(int) $row->id] ?? 0) : null,
                );
            },
            $rows,
        );

        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new ShowcasePage(
            items: $items,
            nextCursor: $hasMore && $last !== null
                ? (new ShowcaseCursor((int) $last->price_minor, (int) $last->id))->encode()
                : null,
        );
    }

    /**
     * @return list<object{id: int, sku: string, name: string, price_minor: int|string, currency: string, supply_mode: string}>
     */
    private function products(?ProductType $type, ?ShowcaseCursor $cursor, int $limit): array
    {
        $conditions = ['is_active', 'in_stock'];
        $bindings = [];

        if ($type !== null) {
            $conditions[] = 'type = ?';
            $bindings[] = $type->value;
        }

        if ($cursor !== null) {
            // Кортежное сравнение, а не «цена > X OR (цена = X И id > Y)»:
            // только в такой форме PostgreSQL использует индекс как единый
            // ключ поиска и прыгает сразу в нужное место.
            $conditions[] = '(price_minor, id) > (?, ?)';
            $bindings[] = $cursor->priceMinor;
            $bindings[] = $cursor->id;
        }

        $bindings[] = $limit;

        /** @var list<object{id: int, sku: string, name: string, price_minor: int|string, currency: string, supply_mode: string}> $rows */
        $rows = $this->db->select(
            'SELECT id, sku, name, price_minor, currency, supply_mode
               FROM products
              WHERE '.implode(' AND ', $conditions).'
              ORDER BY price_minor, id
              LIMIT ?',
            $bindings,
        );

        return $rows;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function availableCounts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var list<object{product_id: int, available_count: int}> $rows */
        $rows = $this->db->select(
            'SELECT product_id, available_count FROM product_stock WHERE product_id = ANY(?)',
            ['{'.implode(',', $productIds).'}'],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->product_id] = (int) $row->available_count;
        }

        return $map;
    }

    /**
     * План выполнения горячего запроса — для команды shop:explain-showcase
     * и для теста, который проверяет ПЛАН, а не только время ответа.
     *
     * @return array<string, mixed>
     */
    public function explainPage(?ProductType $type, int $limit): array
    {
        $conditions = ['is_active', 'in_stock'];
        $bindings = [];

        if ($type !== null) {
            $conditions[] = 'type = ?';
            $bindings[] = $type->value;
        }

        $bindings[] = $limit;

        /** @var list<object{"QUERY PLAN": string}> $rows */
        $rows = $this->db->select(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
             SELECT id, sku, name, price_minor, currency, supply_mode
               FROM products
              WHERE '.implode(' AND ', $conditions).'
              ORDER BY price_minor, id
              LIMIT ?',
            $bindings,
        );

        $decoded = json_decode($rows[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR);

        // json_decode отдаёт mixed; сужаем здесь, наружу mixed не выходит.
        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            return [];
        }

        /** @var array<string, mixed> $plan */
        $plan = $decoded[0];

        return $plan;
    }
}
