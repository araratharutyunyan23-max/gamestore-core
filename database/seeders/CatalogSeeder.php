<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\SupplyMode;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Каталог из приложения к заданию — все 12 SKU, цены в копейках.
 *
 * Режим поставки распределён намеренно, чтобы оба пути выдачи были задействованы:
 * ключи и подарочные карты выдаются из собственного пула (одна транзакция, без
 * сети), пополнения и подписки — через внешнего поставщика (сетевой вызов,
 * ловушка таймаута, fallback A→B). Если бы весь каталог был pool, этап 3 нечем
 * было бы продемонстрировать.
 *
 * Сидер идемпотентен: гоняется повторно между прогонами состязательных тестов.
 */
final class CatalogSeeder extends Seeder
{
    /**
     * Цена в задании дана в рублях; в системе деньги живут только в копейках
     * (CLAUDE.md §5.7), поэтому пересчёт выполняется здесь один раз.
     *
     * @var list<array{sku: string, name: string, type: ProductType, price_rub: int, supply: SupplyMode}>
     */
    private const PRODUCTS = [
        ['sku' => 'STEAM-TOPUP-500', 'name' => 'Пополнение Steam 500 ₽', 'type' => ProductType::Topup, 'price_rub' => 500, 'supply' => SupplyMode::Supplier],
        ['sku' => 'STEAM-TOPUP-1000', 'name' => 'Пополнение Steam 1000 ₽', 'type' => ProductType::Topup, 'price_rub' => 1000, 'supply' => SupplyMode::Supplier],
        ['sku' => 'STEAM-TOPUP-2500', 'name' => 'Пополнение Steam 2500 ₽', 'type' => ProductType::Topup, 'price_rub' => 2500, 'supply' => SupplyMode::Supplier],
        ['sku' => 'KEY-CS2-PRIME', 'name' => 'CS2 Prime Status ключ', 'type' => ProductType::Key, 'price_rub' => 1290, 'supply' => SupplyMode::Pool],
        ['sku' => 'KEY-GTA5', 'name' => 'GTA V ключ активации', 'type' => ProductType::Key, 'price_rub' => 1990, 'supply' => SupplyMode::Pool],
        ['sku' => 'KEY-EFT', 'name' => 'Escape from Tarkov ключ', 'type' => ProductType::Key, 'price_rub' => 3490, 'supply' => SupplyMode::Pool],
        ['sku' => 'SUB-DISCORD-1M', 'name' => 'Discord Nitro 1 месяц', 'type' => ProductType::Subscription, 'price_rub' => 399, 'supply' => SupplyMode::Supplier],
        ['sku' => 'SUB-YT-3M', 'name' => 'YouTube Premium 3 месяца', 'type' => ProductType::Subscription, 'price_rub' => 1490, 'supply' => SupplyMode::Supplier],
        ['sku' => 'SUB-SPOTIFY-1M', 'name' => 'Spotify Premium 1 месяц', 'type' => ProductType::Subscription, 'price_rub' => 299, 'supply' => SupplyMode::Supplier],
        ['sku' => 'GIFT-PSN-1000', 'name' => 'PlayStation Store карта 1000 ₽', 'type' => ProductType::GiftCard, 'price_rub' => 1000, 'supply' => SupplyMode::Pool],
        ['sku' => 'GIFT-XBOX-1500', 'name' => 'Xbox Gift Card 1500 ₽', 'type' => ProductType::GiftCard, 'price_rub' => 1500, 'supply' => SupplyMode::Pool],
        ['sku' => 'GIFT-ROBLOX-800', 'name' => 'Roblox 800 Robux', 'type' => ProductType::GiftCard, 'price_rub' => 890, 'supply' => SupplyMode::Pool],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $row) {
            Product::query()->updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'price_minor' => $row['price_rub'] * 100,
                    'currency' => 'RUB',
                    'supply_mode' => $row['supply'],
                    'is_active' => true,
                ],
            );
        }
    }

    /** @return list<string> */
    public static function skus(): array
    {
        return array_map(static fn (array $row): string => $row['sku'], self::PRODUCTS);
    }
}
