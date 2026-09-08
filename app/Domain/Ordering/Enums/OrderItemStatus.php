<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Состояние позиции заказа.
 *
 * Зеркалит статусы заказа, но не совпадает с ними: у позиции появляется
 * `refunded`, которого у заказа первого этапа быть не могло — возвращать
 * часть денег было не за что, товар в заказе был один.
 *
 * Статус ЗАКАЗА во втором этапе становится производным от этих: заказ
 * доставлен, когда доставлены все позиции, и частично доставлен, когда
 * доставлена хотя бы одна, а остальные возвращены.
 */
enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    /**
     * Из финального состояния выхода нет.
     *
     * `refunded` финален вместе с `delivered`: деньги за позицию уже вернули,
     * и повторная попытка выдать по ней товар означала бы товар без оплаты.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::Refunded, self::Cancelled => true,
            self::Pending, self::Delivering, self::OutOfStock, self::DeliveryFailed => false,
        };
    }

    /** Позиция ждёт выдачи — множество, с которым работает подметальщик. */
    public function awaitsDelivery(): bool
    {
        return match ($this) {
            self::Pending, self::Delivering, self::OutOfStock, self::DeliveryFailed => true,
            self::Delivered, self::Refunded, self::Cancelled => false,
        };
    }

    /**
     * Позиция стоит денег покупателю.
     *
     * Основа инварианта «оплачено = выдано + возвращено»: ровно эти состояния
     * означают, что деньги за позицию ещё не закрыты ни выдачей, ни возвратом.
     */
    public function holdsCustomerMoney(): bool
    {
        return match ($this) {
            self::Pending, self::Delivering, self::OutOfStock, self::DeliveryFailed => true,
            self::Delivered, self::Refunded, self::Cancelled => false,
        };
    }

    /**
     * Разрешённые переходы позиции.
     *
     * Список тот же по духу, что у заказа, с одним добавлением: из тупиков
     * (`out_of_stock`, `delivery_failed`) есть выход не только назад в выдачу,
     * но и в `refunded` — за невыданную позицию деньги возвращаются, и это
     * штатное завершение, а не авария.
     *
     * Обратной дороги из `refunded` нет: деньги уже вернули, и выдать по такой
     * позиции товар означало бы отдать его бесплатно.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target || $this->isFinal()) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($target, [self::Delivering, self::Cancelled], true),
            self::Delivering => in_array(
                $target,
                [self::Delivered, self::OutOfStock, self::DeliveryFailed, self::Cancelled],
                true,
            ),
            self::OutOfStock, self::DeliveryFailed => in_array(
                $target,
                [self::Delivering, self::Refunded, self::Cancelled],
                true,
            ),
            self::Delivered, self::Refunded, self::Cancelled => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function awaitingDelivery(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->awaitsDelivery()));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
