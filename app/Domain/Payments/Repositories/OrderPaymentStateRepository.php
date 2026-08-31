<?php

declare(strict_types=1);

namespace App\Domain\Payments\Repositories;

use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Models\PaymentEvent;
use Illuminate\Database\ConnectionInterface;

/**
 * Проекция состояния оплаты.
 *
 * Отдельный репозиторий, потому что запрос здесь несущий, а не вспомогательный:
 * именно он реализует требование «вебхуки могут прийти не по порядку».
 */
final readonly class OrderPaymentStateRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Монотонный upsert по кортежу (occurred_at, received_at, id).
     *
     * Решает не порядок доставки, а метка времени события у платёжной системы.
     * Устаревшее событие не проходит условие WHERE и не меняет проекцию.
     *
     * Сырой SQL здесь оправдан: `ON CONFLICT ... DO UPDATE ... WHERE` с
     * кортежным сравнением билдер не выражает, а именно это условие и делает
     * запись монотонной.
     *
     * @return bool false, если событие устарело
     */
    public function project(int $orderId, PaymentEvent $event, PaymentProjectionState $state): bool
    {
        $affected = $this->db->affectingStatement(<<<'SQL'
            INSERT INTO order_payment_states
                (order_id, state, occurred_at, received_at, event_row_id, last_event_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (order_id) DO UPDATE
               SET state = EXCLUDED.state,
                   occurred_at = EXCLUDED.occurred_at,
                   received_at = EXCLUDED.received_at,
                   event_row_id = EXCLUDED.event_row_id,
                   last_event_id = EXCLUDED.last_event_id,
                   updated_at = now()
             WHERE (order_payment_states.occurred_at,
                    order_payment_states.received_at,
                    order_payment_states.event_row_id)
                 < (EXCLUDED.occurred_at, EXCLUDED.received_at, EXCLUDED.event_row_id)
        SQL, [
            $orderId,
            $state->value,
            $event->occurred_at ?? $event->received_at,
            $event->received_at,
            $event->id,
            $event->event_id,
        ]);

        return $affected === 1;
    }
}
