<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\SupplyMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Товар каталога.
 *
 * Модель — только отображение таблицы: ни одного бизнес-метода (CLAUDE.md §1.2).
 * Аннотации @property нужны PHPStan level 9: без них обращение к атрибуту даёт mixed.
 *
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property ProductType $type
 * @property int $price_minor
 * @property string $currency
 * @property SupplyMode $supply_mode
 * @property bool $is_active
 * @property bool $in_stock
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ProductStock|null $stock
 * @property-read Collection<int, LicenseKey> $licenseKeys
 */
final class Product extends Model
{
    protected $table = 'products';

    /**
     * in_stock намеренно НЕ заполняем: флаг ведёт триггер product_stock_flag_*,
     * и запись из кода создала бы второй источник истины (CLAUDE.md §5.6).
     *
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'name',
        'type',
        'price_minor',
        'currency',
        'supply_mode',
        'is_active',
    ];

    /** @return HasOne<ProductStock, $this> */
    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class, 'product_id');
    }

    /** @return HasMany<LicenseKey, $this> */
    public function licenseKeys(): HasMany
    {
        return $this->hasMany(LicenseKey::class, 'product_id');
    }

    /**
     * Витрина: только активные и с остатком. Предикат совпадает с predicate
     * покрывающего индекса products_showcase_cov_idx, иначе Index Only Scan
     * не выберется.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOnShowcase(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('in_stock', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'supply_mode' => SupplyMode::class,
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'in_stock' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
