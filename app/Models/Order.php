<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Заказ. Одна позиция, quantity = 1 (CLAUDE.md §10.1).
 *
 * Никаких методов вида isPaid()/markDelivered(): переходы живут в
 * OrderStateMachine, а фактические записи — в репозитории условными UPDATE.
 * Read-modify-write через модель здесь запрещён: он затирает изменения
 * конкурента, который выиграл гонку.
 *
 * @property int $id
 * @property string $public_id
 * @property string $idempotency_key
 * @property int $product_id
 * @property string $sku
 * @property int $amount_minor
 * @property string $currency
 * @property OrderStatus $status
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property string|null $lease_owner
 * @property int $delivery_epoch
 * @property int $restock_waits
 * @property Carbon $status_changed_at
 * @property Carbon $next_action_at
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property Carbon|null $paid_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product $product
 */
final class Order extends Model
{
    protected $table = 'orders';

    /**
     * Аренда, эпоха и статус НЕ заполняются массово: их двигают только условные
     * UPDATE с проверкой предусловия, иначе теряется смысл fencing-токена.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'idempotency_key',
        'product_id',
        'sku',
        'amount_minor',
        'currency',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Множество подметальщика. Предикат совпадает с orders_worklist_idx.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->whereIn('status', OrderStatus::awaitingDelivery());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'amount_minor' => 'integer',
            'delivery_epoch' => 'integer',
            'restock_waits' => 'integer',
            'needs_review' => 'boolean',
            'status' => OrderStatus::class,
            'lease_expires_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'next_action_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
