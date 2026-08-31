<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
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
        private OrderStateMachine $stateMachine,
    ) {}

    public function execute(string $publicId, ?Throwable $exception = null): void
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null || $order->delivery !== null) {
            // Заказ уже выдан: исключение случилось после успешной выдачи,
            // и трогать финальный статус нельзя.
            return;
        }

        $this->stateMachine->tryTransition($order, OrderStatus::DeliveryFailed, reason: 'attempts_exhausted');

        StructuredLog::delivery('delivery_failed', $publicId, reason: $exception?->getMessage() ?? 'attempts_exhausted');
    }
}
