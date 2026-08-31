<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Наполнение каталога для проверки витрины под объёмом.
 *
 * Генерация идёт одним оператором через generate_series: вставка тысяч строк
 * по одной заняла бы минуты и мерила бы скорость PHP, а не базы.
 *
 * Строки остатка создаются триггером products_ensure_stock_row, поэтому
 * отдельно их вставлять не нужно — и это заодно проверка, что триггер
 * справляется с массовой вставкой.
 */
final class SeedBulkCatalogCommand extends Command
{
    protected $signature = 'shop:seed-bulk {--count=5000 : Сколько SKU сгенерировать}';

    protected $description = 'Сгенерировать тысячи SKU для проверки витрины под объёмом';

    public function handle(ConnectionInterface $db): int
    {
        $count = max(1, (int) $this->option('count'));

        $this->components->info("Генерирую {$count} SKU…");

        $db->statement(<<<'SQL'
            INSERT INTO products (sku, name, type, price_minor, currency, supply_mode, is_active, created_at, updated_at)
            SELECT 'BULK-' || lpad(g::text, 7, '0'),
                   'Нагрузочный товар №' || g,
                   (ARRAY['topup','key','subscription','giftcard'])[1 + (g % 4)],
                   -- Цены намеренно повторяются: пагинация обязана быть
                   -- устойчивой к неуникальному ключу сортировки.
                   1000 + (g % 500) * 100,
                   'RUB',
                   CASE WHEN g % 2 = 0 THEN 'pool' ELSE 'supplier' END,
                   true,
                   now(),
                   now()
              FROM generate_series(1, ?) AS g
            ON CONFLICT (sku) DO NOTHING
        SQL, [$count]);

        // Половина каталога — pool-товары, им нужен ненулевой остаток,
        // иначе они не попадут на витрину и замер будет ни о чём.
        $db->statement(<<<'SQL'
            UPDATE product_stock s
               SET available_count = 10 + (s.product_id % 40)
              FROM products p
             WHERE p.id = s.product_id
               AND p.sku LIKE 'BULK-%'
               AND p.supply_mode = 'pool'
        SQL);

        // VACUUM обязателен, а не «для чистоты»: Index Only Scan возможен
        // только когда карта видимости говорит, что страница видна всем.
        // После массовой вставки она не заполнена, и план деградирует
        // в обычный Index Scan с обращениями к куче.
        $db->statement('VACUUM ANALYZE products');

        /** @var list<object{total: int}> $rows */
        $rows = $db->select('SELECT count(*)::int AS total FROM products WHERE is_active AND in_stock');

        $this->components->twoColumnDetail('Товаров на витрине', (string) $rows[0]->total);

        return self::SUCCESS;
    }
}
