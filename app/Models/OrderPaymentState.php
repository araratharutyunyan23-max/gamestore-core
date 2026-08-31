<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payments\Enums\PaymentProjectionState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Проекция состояния оплаты, отделённая от FSM выполнения заказа.
 *
 * Доставка обязана читать её сразу после захвата аренды: без этой проверки
 * поздний failed, пришедший между paid и стартом выдачи, отдаёт товар
 * бесплатно и молча.
 *
 * @property int $order_id
 * @property PaymentProjectionState $state
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property int $event_row_id
 * @property string $last_event_id
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 */
final class OrderPaymentState extends Model
{
    protected $table = 'order_payment_states';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'state',
        'occurred_at',
        'received_at',
        'event_row_id',
        'last_event_id',
    ];

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
            'event_row_id' => 'integer',
            'state' => PaymentProjectionState::class,
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
