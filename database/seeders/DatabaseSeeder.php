<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Порядок важен: ключи ссылаются на товары, поэтому каталог идёт первым.
 * Все сидеры идемпотентны — их можно гонять повторно между прогонами
 * состязательных тестов, не пересоздавая схему.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            LicenseKeySeeder::class,
            LedgerAccountSeeder::class,
        ]);
    }
}
