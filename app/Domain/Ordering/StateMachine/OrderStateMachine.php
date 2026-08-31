<?php

declare(strict_types=1);

namespace App\Domain\Ordering\StateMachine;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use App\Models\Order;
use Illuminate\Database\ConnectionInterface;

/**
 * Единственное место, где меняется статус заказа.
 *
 * Перевод выполняется условным UPDATE с проверкой текущего статуса, а не
 * read-modify-write через модель: второй способ затирает результат конкурента,
 * который выиграл гонку. «Я выиграл» здесь означает ровно одно — UPDATE
 * затронул одну строку.
 */
final readonly class OrderStateMachine
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Для событийных путей: не бросает, возвращает исход.
     */
    public function tryTransition(
        Order $order,
        OrderStatus $to,
        ?string $reason = null,
        ?string $traceId = null,
    ): TransitionResult {
        $from = $order->status;

        if ($from->isFinal()) {
            return TransitionResult::IgnoredFinal;
        }

        if (! $from->canTransitionTo($to)) {
            return TransitionResult::IgnoredIllegal;
        }

        $columns = $this->columnsFor($to);

        $affected = $this->db->table('orders')
            ->where('id', $order->id)
            ->where('status', $from->value)
            ->update($columns);

        if ($affected !== 1) {
            return TransitionResult::LostRace;
        }

        $this->recordTransition($order->id, $from, $to, $reason, $traceId);

        // Состояние в памяти синхронизируется без запроса. refresh() перечитал бы
        // заказ вместе со всеми связями — четыре запроса на каждый переход,
        // а переходов у одного заказа три-четыре.
        $order->forceFill($columns)->syncOriginal();

        return TransitionResult::Applied;
    }

    /**
     * Для программных путей, где нелегальный переход — это дефект кода.
     *
     * @throws IllegalTransition
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?string $reason = null,
        ?string $traceId = null,
    ): void {
        $result = $this->tryTransition($order, $to, $reason, $traceId);

        if ($result !== TransitionResult::Applied) {
            throw IllegalTransition::from($order->public_id, $order->status, $to, $result);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsFor(OrderStatus $to): array
    {
        $columns = [
            'status' => $to->value,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ];

        // Отметки времени ставятся тем же оператором, что и статус: отдельным
        // UPDATE они разъезжаются при падении между двумя запросами.
        return match ($to) {
            OrderStatus::Paid => $columns + ['paid_at' => now()],
            OrderStatus::Delivered => $columns + ['delivered_at' => now(), 'next_action_at' => now()],
            OrderStatus::PaymentFailed, OrderStatus::Cancelled => $columns + ['failed_at' => now()],
            OrderStatus::Created, OrderStatus::Delivering,
            OrderStatus::OutOfStock, OrderStatus::DeliveryFailed => $columns,
        };
    }

    private function recordTransition(
        int $orderId,
        OrderStatus $from,
        OrderStatus $to,
        ?string $reason,
        ?string $traceId,
    ): void {
        // Аудит переходов — независимое доказательство «выдача ровно одна»,
        // не зависящее от текущего значения orders.status.
        $this->db->table('order_status_transitions')->insert([
            'order_id' => $orderId,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor' => 'system',
            'trace_id' => $traceId,
            'created_at' => now(),
        ]);
    }
}
