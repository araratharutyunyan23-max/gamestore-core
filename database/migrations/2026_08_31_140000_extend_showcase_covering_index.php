<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Покрывающий индекс витрины обязан покрывать ЗАПРОС, а не намерение.
 *
 * Первая версия включала (sku, name, currency), но горячий запрос выбирает ещё
 * и supply_mode — по нему решается, показывать ли число остатка вообще (у товара
 * от поставщика локального остатка нет). Одной недостающей колонки хватило,
 * чтобы план стал Index Scan вместо Index Only Scan: база вынуждена сходить
 * в основную таблицу за каждой строкой.
 *
 * Замерено на 5012 товарах: 27 страниц вместо 5.
 *
 * Урок общий: «покрывающий индекс» — это не свойство индекса, а отношение между
 * индексом и конкретным запросом. Проверяется только планом.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_showcase_cov_idx');
        DB::statement('CREATE INDEX products_showcase_cov_idx
            ON products (type, price_minor, id) INCLUDE (sku, name, currency, supply_mode)
            WHERE is_active AND in_stock');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_showcase_cov_idx');
        DB::statement('CREATE INDEX products_showcase_cov_idx
            ON products (type, price_minor, id) INCLUDE (sku, name, currency)
            WHERE is_active AND in_stock');
    }
};
