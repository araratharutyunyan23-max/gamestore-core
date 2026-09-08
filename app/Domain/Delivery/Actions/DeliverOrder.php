<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Ordering\Actions\DeriveOrderStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Repositories\ReconciliationFindingRepository;
use App\Models\Order;
use App\Support\StructuredLog;

/**
 * Выдача заказа: обход его позиций.
 *
 * Собственной механики выдачи здесь больше нет — она вся в DeliverOrderItem,
 * потому что единицей выдачи стала позиция. Здесь остаётся то, что относится
 * к заказу целиком: проверка оплаты и пересчёт статуса.
 *
 * Позиции обходятся ПОСЛЕДОВАТЕЛЬНО и независимо: неудача одной не отменяет
 * остальных. Это и есть требование ТЗ 1.2 — что смогли выдать, остаётся
 * у покупателя. Прерывать обход на первой неудаче означало бы не выдать
 * товар, который лежит в собственном пуле и готов прямо сейчас.
 *
 * Проверка оплаты — на уровне заказа, а не позиции: платёж один на заказ.
 * Читается ПРОЕКЦИЯ ПЛАТЕЖА, а не статус заказа: поздний failed, пришедший
 * между признанием оплаты и стартом выдачи, иначе отдаёт товар бесплатно
 * и молча — статус к этому моменту уже 'paid'.
 */
final readonly class DeliverOrder
{
    public function __construct(
        private OrderRepository $orders,
        private OrderItemRepository $items,
        private DeliverOrderItem $deliverItem,
        private DeriveOrderStatus $deriveStatus,
        private ReconciliationFindingRepository $findings,
    ) {}

    /**
     * @throws \Throwable любая ошибка внутри транзакции доставки
     */
    public function execute(string $publicId): DeliveryOutcome
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            return DeliveryOutcome::NotDeliverable;
        }

        // Проверка «уже выдано» идёт ПЕРЕД проверкой «ждёт выдачи»: выданный
        // заказ не ждёт выдачи по определению, и обратный порядок превращал бы
        // штатный повтор задачи в NotDeliverable. Повтор здесь — норма:
        // очередь доставляет at-least-once.
        if ($order->status === OrderStatus::Delivered) {
            return DeliveryOutcome::AlreadyDelivered;
        }

        if (! $order->status->awaitsDelivery()) {
            return DeliveryOutcome::NotDeliverable;
        }

        if ($order->paymentState?->state !== PaymentProjectionState::Paid) {
            $this->flagPaymentRevoked($order);

            return DeliveryOutcome::PaymentNotConfirmed;
        }

        $pending = $this->items->awaitingDeliveryForOrder($order->id);

        if ($pending === []) {
            // Выдавать нечего: либо всё уже выдано, либо всё закрыто иначе.
            // Статус всё равно пересчитывается — заказ мог застрять в
            // delivering после падения воркера, и вытащить его оттуда может
            // только пересчёт.
            $this->deriveStatus->execute($order);

            return DeliveryOutcome::AlreadyDelivered;
        }

        $outcomes = [];

        foreach ($pending as $item) {
            $outcomes[] = $this->deliverItem->execute($order, $item);
        }

        // Пересчёт ПОСЛЕ обхода: статус заказа обязан отражать то, что реально
        // лежит в базе, а не то, чем закончилась последняя позиция.
        $this->deriveStatus->execute($order);

        return $this->summarise($outcomes);
    }

    /**
     * Свести исходы позиций к одному исходу заказа.
     *
     * Для заказа из одной позиции возвращает ровно её исход — контракт первого
     * этапа не меняется. Для многопозиционного показывает самое тревожное из
     * случившегося: заказ, где одна позиция ждёт разрешения неизвестности,
     * нельзя объявлять выданным только потому, что две другие удались.
     *
     * @param  list<DeliveryOutcome>  $outcomes
     */
    private function summarise(array $outcomes): DeliveryOutcome
    {
        if ($outcomes === []) {
            return DeliveryOutcome::NotDeliverable;
        }

        // Порядок — от самого тревожного к самому спокойному.
        $priority = [
            DeliveryOutcome::PaymentNotConfirmed,
            DeliveryOutcome::AwaitingResolution,
            DeliveryOutcome::DeliveryFailed,
            DeliveryOutcome::SupplierExhausted,
            DeliveryOutcome::OutOfStock,
            DeliveryOutcome::RateLimited,
            DeliveryOutcome::AlreadyInProgress,
            DeliveryOutcome::NotDeliverable,
        ];

        foreach ($priority as $candidate) {
            if (in_array($candidate, $outcomes, true)) {
                return $candidate;
            }
        }

        // Осталась только выдача: либо нашими руками, либо соседа —
        // для заказа это один и тот же результат.
        return in_array(DeliveryOutcome::Delivered, $outcomes, true)
            ? DeliveryOutcome::Delivered
            : DeliveryOutcome::AlreadyDelivered;
    }

    private function flagPaymentRevoked(Order $order): void
    {
        $this->orders->flagForReview($order->id, 'payment_revoked_before_delivery');

        $this->findings->record(FindingKind::PaymentRevoked, $order->id, $order->public_id, [
            'projection' => $order->paymentState?->state->value,
        ]);

        StructuredLog::delivery('delivery_blocked', $order->public_id, reason: 'payment_not_confirmed');
    }
}
