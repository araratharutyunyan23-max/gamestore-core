<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Ordering\Actions\DeriveOrderStatus;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Ordering\StateMachine\OrderItemStateMachine;
use App\Support\StructuredLog;
use Throwable;

/**
 * Перевод заказа в восстановимый отказ после исчерпания попыток выдачи.
 *
 * Вынесено в Action, а не написано внутри Job::failed(): задача остаётся
 * оркестратором без бизнес-правил, и тот же путь переиспользует фоновая
 * доводка.
 */
final readonly class FailDelivery
{
    public function __construct(
        private OrderRepository $orders,
        private OrderItemRepository $items,
        private OrderItemStateMachine $itemStateMachine,
        private DeriveOrderStatus $deriveStatus,
    ) {}

    public function execute(string $publicId, ?Throwable $exception = null): void
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            return;
        }

        $reason = $exception?->getMessage() ?? 'attempts_exhausted';

        // Проваливаются ПОЗИЦИИ, которые ещё не закрыты. Уже выданные не
        // трогаются: исключение могло случиться после успешной выдачи части
        // товара, и откатывать её нельзя — покупатель уже получил код.
        //
        // Раньше здесь стояла проверка «у заказа есть выдача», и с несколькими
        // позициями она означала бы, что одна удачная выдача отменяет отказ
        // по всем остальным.
        foreach ($this->items->awaitingDeliveryForOrder($order->id) as $item) {
            $this->itemStateMachine->tryTransition($item, OrderItemStatus::DeliveryFailed, reason: 'attempts_exhausted');
        }

        $this->deriveStatus->execute($order);

        StructuredLog::delivery('delivery_failed', $publicId, reason: $reason);
    }
}
