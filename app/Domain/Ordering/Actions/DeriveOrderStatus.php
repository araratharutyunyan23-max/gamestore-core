<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Models\Order;

/**
 * Статус заказа ВЫВОДИТСЯ из статусов позиций, а не выставляется по ходу.
 *
 * Требование ТЗ 1.5 читается буквально: «заказ доходит до конечного состояния
 * даже после аварийной остановки и перезапуска в середине выдачи». Статус,
 * выставленный оптимистично в конце удачного пути, этого не переживает —
 * упавший на середине воркер оставляет заказ в delivering навсегда, и никакой
 * повтор его оттуда не достанет, потому что достать некому.
 *
 * Выводимый статус переживает: любой следующий проход — обычная выдача,
 * подметальщик, сверка — пересчитывает его из того, что реально лежит в базе.
 *
 * Правило простое и совпадает с тем, что покупатель видит в заказе:
 *  — все позиции выданы          → delivered;
 *  — все закрыты, но не все выданы → delivery_failed (частичный исход
 *    описывается позициями, а не отдельным статусом заказа);
 *  — хоть одна ждёт склада        → out_of_stock;
 *  — иначе                        → delivering.
 */
final readonly class DeriveOrderStatus
{
    public function __construct(
        private OrderItemRepository $items,
        private OrderStateMachine $stateMachine,
    ) {}

    public function execute(Order $order): OrderStatus
    {
        $target = $this->statusFor($this->items->statusCountsForOrder($order->id));

        if ($target === null || $order->status === $target) {
            return $order->status;
        }

        // Машина состояний не пускает paid -> delivered напрямую: путь заказа
        // идёт через delivering. Ослаблять её ради пересчёта нельзя — запрет
        // на нелегальный переход это одна из немногих вещей, которые ловят
        // дефект кода сразу. Поэтому пересчёт идёт ЛЕГАЛЬНЫМ путём, а история
        // переходов остаётся правдивой: заказ действительно побывал в выдаче.
        if (! $order->status->canTransitionTo($target)
            && $order->status->canTransitionTo(OrderStatus::Delivering)) {
            $this->stateMachine->tryTransition($order, OrderStatus::Delivering, reason: 'delivery_started');
        }

        // tryTransition, а не transition: пересчёт вызывается из нескольких
        // мест одновременно, и проигрыш гонки здесь — норма, а не дефект.
        $this->stateMachine->tryTransition($order, $target, reason: 'derived_from_items');

        return $order->status;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function statusFor(array $counts): ?OrderStatus
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return null;
        }

        $delivered = $counts[OrderItemStatus::Delivered->value] ?? 0;
        $closed = $delivered
            + ($counts[OrderItemStatus::Refunded->value] ?? 0)
            + ($counts[OrderItemStatus::Cancelled->value] ?? 0);

        // Позиции, по которым работа ИДЁТ прямо сейчас. Отличать их от
        // застрявших обязательно: и те и другие «не финальные», но заказ,
        // где все позиции упёрлись в тупик, ничем не занят — он ждёт
        // вмешательства, и статус обязан это показывать.
        $active = ($counts[OrderItemStatus::Pending->value] ?? 0)
            + ($counts[OrderItemStatus::Delivering->value] ?? 0);

        $outOfStock = $counts[OrderItemStatus::OutOfStock->value] ?? 0;

        if ($delivered === $total) {
            return OrderStatus::Delivered;
        }

        if ($closed === $total) {
            // Часть выдана, часть возвращена — заказ завершён, но не полностью
            // исполнен. Отдельного статуса «частично» у заказа нет намеренно:
            // правда о том, что именно выдано, живёт в позициях, и дублировать
            // её в статусе значит завести второй источник истины.
            return OrderStatus::DeliveryFailed;
        }

        if ($active > 0) {
            return OrderStatus::Delivering;
        }

        // Работа встала. Ожидание склада отделено от отказа поставщика, потому
        // что первое — это пауза, которая закончится сама после пополнения,
        // а второе требует повтора или возврата.
        return $outOfStock > 0 ? OrderStatus::OutOfStock : OrderStatus::DeliveryFailed;
    }
}
