<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * То, что выдаётся: конкретная позиция конкретного заказа.
 *
 * Путь к поставщику больше не знает про Eloquent-модели, и это не эстетика.
 * Раньше он принимал Order и брал оттуда sku и эпоху; с многопозиционным
 * заказом такой код молча продолжил бы работать — у заказа sku по-прежнему
 * есть, — но выдавал бы товар ПЕРВОЙ позиции для каждой из них.
 *
 * Явный набор полей делает такую ошибку невозможной: собрать цель без номера
 * строки нельзя.
 */
final readonly class DeliveryTarget
{
    public function __construct(
        public int $itemId,
        public int $orderId,
        public int $productId,
        public string $orderPublicId,
        public int $lineNo,
        public string $sku,
        public int $deliveryEpoch,
    ) {}

    public static function from(Order $order, OrderItem $item): self
    {
        return new self(
            itemId: $item->id,
            orderId: $order->id,
            productId: $item->product_id,
            orderPublicId: $order->public_id,
            lineNo: $item->line_no,
            sku: $item->sku,
            deliveryEpoch: $item->delivery_epoch,
        );
    }

    /**
     * Человекочитаемая ссылка на позицию для логов и находок сверки.
     *
     * Один только public_id заказа во втором этапе неоднозначен: позиций
     * в заказе несколько, и «заказ ord_00042 не выдан» не говорит, какая
     * именно позиция застряла.
     */
    public function reference(): string
    {
        return $this->orderPublicId.'#'.$this->lineNo;
    }
}
