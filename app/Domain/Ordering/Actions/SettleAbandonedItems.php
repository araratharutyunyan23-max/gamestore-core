<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Support\Cfg;
use App\Support\StructuredLog;

/**
 * Расчёт по позициям, которые выдать так и не удалось.
 *
 * ТЗ 1.2 требует, чтобы за невыданное деньги вернулись, а ТЗ 1.5 — чтобы заказ
 * дошёл до конечного состояния. Оба требования закрывает этот проход: он
 * превращает застрявшую позицию в возврат, а заказ — в завершённый.
 *
 * Терпение обязательно. Возврат необратим, и делать его сразу после первого
 * отказа значило бы закрывать заказ в тот момент, когда товар мог появиться
 * на складе минутой позже. Поэтому берутся только позиции, простоявшие
 * в тупике дольше настроенного окна.
 *
 * И так же обязательна ГРАНИЦА терпения: без неё заказ, который не удалось
 * выдать, висел бы вечно, а деньги покупателя — вместе с ним.
 */
final readonly class SettleAbandonedItems
{
    private const BATCH = 100;

    public function __construct(
        private OrderRepository $orders,
        private OrderItemRepository $items,
        private RefundOrderItem $refund,
        private DeriveOrderStatus $deriveStatus,
    ) {}

    /** @return int сколько позиций закрыто возвратом */
    public function execute(): int
    {
        $items = $this->items->readyForRefund(Cfg::refundAfterMinutes(), self::BATCH);

        $refunded = 0;
        /** @var array<int, true> $touchedOrders */
        $touchedOrders = [];

        foreach ($items as $item) {
            $order = $this->orders->findById($item->order_id);

            if ($order === null) {
                continue;
            }

            if ($this->refund->execute($order, $item)) {
                $refunded++;
            }

            $touchedOrders[$order->id] = true;
        }

        // Статус заказа пересчитывается ОДИН раз на заказ, а не на каждую
        // позицию: у заказа из десяти невыданных позиций это десять лишних
        // пересчётов, каждый со своей группировкой.
        foreach (array_keys($touchedOrders) as $orderId) {
            $order = $this->orders->findById($orderId);

            if ($order !== null) {
                $this->deriveStatus->execute($order);
            }
        }

        if ($refunded > 0) {
            StructuredLog::delivery('items_settled', 'batch', reason: (string) $refunded);
        }

        return $refunded;
    }
}
