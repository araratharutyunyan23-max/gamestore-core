<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Payments\Repositories\PaymentEventRepository;

/**
 * Мостик «платёж применён → запустить выдачу».
 *
 * Вынесен отдельно, чтобы задача применения платежа не знала ни про статусы
 * заказа, ни про очередь доставки: у неё одна ответственность.
 */
final readonly class DispatchDelivery
{
    public function __construct(
        private PaymentEventRepository $events,
        private OrderRepository $orders,
    ) {}

    public function forEventOrder(string $eventId): void
    {
        $event = $this->events->findByEventId($eventId);

        if ($event === null) {
            return;
        }

        $order = $this->orders->findByPublicId($event->order_public_id);

        if ($order === null || $order->status !== OrderStatus::Paid) {
            return;
        }

        DeliverOrderJob::dispatch($order->public_id);
    }
}
