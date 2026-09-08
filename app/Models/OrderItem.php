<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ordering\Enums\OrderItemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Позиция заказа — единица выдачи и единица денег.
 *
 * Именно позиция, а не заказ, теперь владеет арендой и эпохой выдачи: две
 * позиции одного заказа идут к разным поставщикам и обязаны выдаваться
 * независимо. Одна аренда на заказ означала бы, что один залипший поставщик
 * держит весь заказ.
 *
 * Как и у заказа, методов вида markDelivered() здесь нет: переходы идут
 * условными UPDATE в репозитории. Read-modify-write через модель затирает
 * изменения конкурента, выигравшего гонку.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $line_no
 * @property string $sku
 * @property int $unit_amount_minor
 * @property string $currency
 * @property OrderItemStatus $status
 * @property string|null $lease_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property string|null $lease_owner
 * @property int $delivery_epoch
 * @property int $restock_waits
 * @property int $priority
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable $next_action_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read Product $product
 * @property-read Delivery|null $delivery
 */
final class OrderItem extends Model
{
    protected $table = 'order_items';

    /**
     * Аренда, эпоха и статус массово не заполняются: их двигают только
     * условные UPDATE с проверкой предусловия, иначе fencing-токен теряет смысл.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'line_no',
        'sku',
        'unit_amount_minor',
        'currency',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Ровно одна выдача на позицию — это держит индекс deliveries_order_item_uq,
     * поэтому связь hasOne, а не hasMany. У ЗАКАЗА такой связи больше нет:
     * выдач у него столько же, сколько позиций.
     *
     * @return HasOne<Delivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'order_item_id');
    }

    /**
     * Множество подметальщика. Предикат совпадает с order_items_worklist_idx —
     * иначе индекс перестанет использоваться, а запрос этого не заметит.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (OrderItemStatus $status): string => $status->value,
            array_filter(OrderItemStatus::cases(), static fn (OrderItemStatus $s): bool => $s->awaitsDelivery()),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'line_no' => 'integer',
            'unit_amount_minor' => 'integer',
            'delivery_epoch' => 'integer',
            'restock_waits' => 'integer',
            'priority' => 'integer',
            'status' => OrderItemStatus::class,
            'lease_expires_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'next_action_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
