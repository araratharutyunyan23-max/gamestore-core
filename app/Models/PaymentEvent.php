<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payments\Enums\PaymentEventState;
use App\Domain\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Инбокс платёжных событий. Он же источник истины для восстановления:
 * подметальщик берёт работу отсюда, а не от статуса заказа. Заказ, застрявший
 * в created с потерянным платежом, не виден ни одному статусному фильтру,
 * а строке с applied_at IS NULL — виден.
 *
 * @property int $id
 * @property string $event_id
 * @property string $order_public_id заказа может ещё не существовать, FK намеренно нет
 * @property PaymentStatus $status
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property Carbon|null $occurred_at
 * @property Carbon $received_at
 * @property Carbon|null $applied_at
 * @property PaymentEventState $process_state
 * @property int $attempts
 * @property string $body_fingerprint
 * @property array<string, mixed> $payload
 */
final class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'order_public_id',
        'status',
        'amount_minor',
        'currency',
        'occurred_at',
        'received_at',
        'process_state',
        'body_fingerprint',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'process_state' => PaymentEventState::class,
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
