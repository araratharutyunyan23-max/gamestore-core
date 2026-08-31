<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\Enums\SupplierName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт выдачи. Ровно один на заказ — это держит deliveries_order_uq,
 * и нарушение 23505 по нему означает «уже выдано», а не сбой.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property SupplyMode $supply_mode
 * @property int|null $license_key_id
 * @property SupplierName|null $supplier
 * @property string|null $request_id
 * @property string $code_encrypted
 * @property string $code_hash
 * @property string $code_last4
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 */
final class Delivery extends Model
{
    protected $table = 'deliveries';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'product_id',
        'supply_mode',
        'license_key_id',
        'supplier',
        'request_id',
        'code_encrypted',
        'code_hash',
        'code_last4',
    ];

    /** @var list<string> */
    protected $hidden = ['code_encrypted', 'code_hash'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'license_key_id' => 'integer',
            'supply_mode' => SupplyMode::class,
            'supplier' => SupplierName::class,
            'code_encrypted' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }
}
