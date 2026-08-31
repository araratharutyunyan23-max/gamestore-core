<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Денормализованный остаток.
 *
 * Источник истины — license_keys; эта строка существует ради витрины, чтобы
 * горячий запрос не считал COUNT(*) по свободным ключам на каждый показ.
 * Расхождение возможно и ловится сверкой (stock_drift), поэтому счётчик НЕ
 * является воротами выдачи: декремент идёт через GREATEST(...,0) и не имеет
 * права уронить продажу (CLAUDE.md §5.6).
 *
 * Строка создаётся триггером products_ensure_stock_row вместе с товаром —
 * иначе UPDATE счётчика молча задел бы ноль строк.
 *
 * @property int $product_id
 * @property int $available_count
 * @property int $reserved_count
 * @property int $issued_count
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 */
final class ProductStock extends Model
{
    protected $table = 'product_stock';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    /** Колонки created_at нет: строка живёт столько же, сколько товар. */
    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'available_count',
        'reserved_count',
        'issued_count',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'available_count' => 'integer',
            'reserved_count' => 'integer',
            'issued_count' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
