<?php

declare(strict_types=1);

namespace App\Domain\Ordering\StateMachine;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Models\OrderItem;
use App\Support\StructuredLog;
use Illuminate\Database\ConnectionInterface;

/**
 * Переходы состояния позиции.
 *
 * Отдельная машина, а не параметр к заказной: множества статусов разные, и
 * склеивание их одним классом с проверками «если это позиция» кончилось бы
 * веткой, которую никто не покрывает.
 *
 * Механика та же и по тем же причинам. Переход — это условный UPDATE с
 * проверкой предыдущего статуса, то есть compare-and-set: «я выиграл» означает
 * ровно одно — затронута одна строка. Read-modify-write через модель затирал бы
 * изменения конкурента, который выиграл гонку.
 */
final readonly class OrderItemStateMachine
{
    public function __construct(private ConnectionInterface $db) {}

    public function tryTransition(
        OrderItem $item,
        OrderItemStatus $to,
        ?string $reason = null,
    ): TransitionResult {
        $from = $item->status;

        if ($from->isFinal()) {
            return TransitionResult::IgnoredFinal;
        }

        if (! $from->canTransitionTo($to)) {
            return TransitionResult::IgnoredIllegal;
        }

        $columns = $this->columnsFor($to);

        $affected = $this->db->table('order_items')
            ->where('id', $item->id)
            ->where('status', $from->value)
            ->update($columns);

        if ($affected !== 1) {
            return TransitionResult::LostRace;
        }

        $this->recordTransition($item, $from, $to, $reason);

        // Состояние в памяти синхронизируется без запроса: refresh() перечитал
        // бы позицию вместе со связями, а переходов у одной позиции три-четыре.
        $item->forceFill($columns)->syncOriginal();

        return TransitionResult::Applied;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsFor(OrderItemStatus $to): array
    {
        $columns = [
            'status' => $to->value,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ];

        // Отметки времени ставятся тем же оператором, что и статус: отдельным
        // UPDATE они разъезжаются при падении между двумя запросами.
        return match ($to) {
            OrderItemStatus::Delivered => $columns + ['delivered_at' => now(), 'next_action_at' => now()],
            OrderItemStatus::Refunded => $columns + ['refunded_at' => now(), 'next_action_at' => now()],
            OrderItemStatus::DeliveryFailed => $columns + ['failed_at' => now()],
            OrderItemStatus::Cancelled => $columns + ['failed_at' => now(), 'next_action_at' => now()],
            OrderItemStatus::Pending, OrderItemStatus::Delivering,
            OrderItemStatus::OutOfStock => $columns,
        };
    }

    private function recordTransition(
        OrderItem $item,
        OrderItemStatus $from,
        OrderItemStatus $to,
        ?string $reason,
    ): void {
        $this->db->table('order_item_status_transitions')->insert([
            'order_item_id' => $item->id,
            'order_id' => $item->order_id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor' => 'system',
            'trace_id' => StructuredLog::traceId(),
            'created_at' => now(),
        ]);
    }
}
