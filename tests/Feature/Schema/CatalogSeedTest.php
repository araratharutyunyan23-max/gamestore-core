<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use App\Domain\Catalog\Enums\SupplyMode;
use App\Models\LicenseKey;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сид обязан в точности соответствовать приложению к заданию: 12 SKU и 50 ключей.
 * Расхождение здесь означает, что проверяющий гоняет сценарии не на тех данных.
 */
final class CatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function catalog_contains_all_twelve_skus_from_the_assignment(): void
    {
        self::assertSame(12, Product::query()->count());

        $expected = [
            'STEAM-TOPUP-500' => 50000,
            'STEAM-TOPUP-1000' => 100000,
            'STEAM-TOPUP-2500' => 250000,
            'KEY-CS2-PRIME' => 129000,
            'KEY-GTA5' => 199000,
            'KEY-EFT' => 349000,
            'SUB-DISCORD-1M' => 39900,
            'SUB-YT-3M' => 149000,
            'SUB-SPOTIFY-1M' => 29900,
            'GIFT-PSN-1000' => 100000,
            'GIFT-XBOX-1500' => 150000,
            'GIFT-ROBLOX-800' => 89000,
        ];

        /** @var array<string, int> $actual */
        $actual = Product::query()->pluck('price_minor', 'sku')->all();

        ksort($expected);
        ksort($actual);

        // Цены сверяются в копейках: в задании они в рублях, и ошибка в 100 раз
        // здесь заблокировала бы все выдачи по amount_mismatch (CLAUDE.md §5.7).
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function the_whole_key_pool_from_the_assignment_is_loaded(): void
    {
        self::assertSame(50, LicenseKey::query()->count());

        // Уникальность отпечатков — это тот же индекс, что запрещает одному коду
        // уйти в два заказа. Дубль в сиде обесценил бы все проверки выдачи.
        self::assertSame(50, LicenseKey::query()->distinct()->count('code_hash'));
    }

    #[Test]
    public function first_and_last_keys_are_stored_verbatim_and_decryptable(): void
    {
        foreach (['LFXC-TNCS-BPCD', '7EQM-K09J-XKUO'] as $code) {
            $key = LicenseKey::query()->where('code_hash', LicenseKey::fingerprint($code))->firstOrFail();

            self::assertSame($code, $key->code_encrypted, 'Код должен расшифровываться обратно в исходный.');
            self::assertSame(mb_substr($code, -4), $key->code_last4);
        }
    }

    #[Test]
    public function stock_counters_match_the_real_number_of_keys(): void
    {
        /** @var list<object{sku: string, counter: int, real: int}> $drift */
        $drift = DB::select(<<<'SQL'
            SELECT p.sku,
                   s.available_count::int AS counter,
                   count(k.id) FILTER (WHERE k.status = 'available')::int AS real
              FROM products p
              JOIN product_stock s ON s.product_id = p.id
              LEFT JOIN license_keys k ON k.product_id = p.id
             WHERE p.supply_mode = 'pool'
             GROUP BY p.sku, s.available_count
            HAVING s.available_count <> count(k.id) FILTER (WHERE k.status = 'available')
        SQL);

        self::assertSame([], $drift, 'Счётчик остатка разошёлся с реальным числом свободных ключей.');
    }

    #[Test]
    public function a_scarce_sku_exists_so_the_out_of_stock_scenario_is_reproducible(): void
    {
        // Критерий приёмки №6 требует пустого остатка. Без заведомо дефицитного SKU
        // его пришлось бы готовить руками перед каждым прогоном.
        $scarce = Product::query()->with('stock')->where('sku', 'KEY-EFT')->firstOrFail();

        self::assertNotNull($scarce->stock);
        self::assertSame(2, $scarce->stock->available_count);
    }

    #[Test]
    public function both_supply_modes_are_present_and_visible_on_the_showcase(): void
    {
        $pool = Product::query()->where('supply_mode', SupplyMode::Pool)->onShowcase()->count();
        $supplier = Product::query()->where('supply_mode', SupplyMode::Supplier)->onShowcase()->count();

        self::assertSame(6, $pool);

        // У supplier-товара локального остатка нет, и наивная логика «in_stock =
        // счётчик > 0» убрала бы половину каталога с витрины навсегда.
        self::assertSame(6, $supplier, 'Товары от поставщика обязаны быть видны на витрине.');
    }

    #[Test]
    public function seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed();

        self::assertSame(12, Product::query()->count());
        self::assertSame(50, LicenseKey::query()->count());
        self::assertSame(50, LicenseKey::query()->where('status', LicenseKeyStatus::Available)->count());

        // Повторный сид не имеет права накрутить счётчик: остаток двигается
        // только вместе с фактической вставкой ключей.
        self::assertSame(
            50,
            (int) DB::table('product_stock')->sum('available_count'),
            'Повторный прогон сидера накрутил счётчик остатка.',
        );
    }
}
