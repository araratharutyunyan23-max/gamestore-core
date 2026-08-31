<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Жизненный цикл заказа.
 *
 * Машина состояний живёт здесь и в OrderStateMachine — ни одного сравнения
 * со статусом за их пределами (CLAUDE.md §3). Список значений обязан совпадать
 * с CHECK-ограничением orders_status_chk; за этим следит тест-страж.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    /**
     * Оплата отозвана до начала выдачи. Не из букваря ТЗ, но без этого состояния
     * поздний failed по ещё не выданному заказу некуда записать, а reaper потом
     * выдаёт товар клиенту, которому уже вернули деньги.
     */
    case Cancelled = 'cancelled';

    /** Финальные состояния необратимы — это подкреплено триггером orders_no_final_downgrade. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::PaymentFailed, self::Cancelled => true,
            self::Created, self::Paid, self::Delivering, self::OutOfStock, self::DeliveryFailed => false,
        };
    }

    /**
     * Восстановимые состояния: не отказ, а пауза. Из них выдача продолжается
     * без задвоения — по тем же локам и уникальным индексам, что и основной путь.
     */
    public function isRecoverable(): bool
    {
        return match ($this) {
            self::OutOfStock, self::DeliveryFailed => true,
            default => false,
        };
    }

    /** Заказ оплачен и ждёт выдачи — множество, с которым работает подметальщик. */
    public function awaitsDelivery(): bool
    {
        return match ($this) {
            self::Paid, self::Delivering, self::OutOfStock, self::DeliveryFailed => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this->isFinal()) {
            return false;
        }

        return in_array($target, $this->allowedTargets(), true);
    }

    /** @return list<self> */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Created => [self::Paid, self::PaymentFailed],
            // Отмена возможна только пока ни одна выдача не начата.
            self::Paid => [self::Delivering, self::Cancelled],
            self::Delivering => [self::Delivered, self::OutOfStock, self::DeliveryFailed],
            // Восстановление: после пополнения остатка или ручного ретрая.
            self::OutOfStock, self::DeliveryFailed => [self::Delivering, self::Cancelled],
            self::Delivered, self::PaymentFailed, self::Cancelled => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /** @return list<self> */
    public static function awaitingDelivery(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->awaitsDelivery()));
    }
}
