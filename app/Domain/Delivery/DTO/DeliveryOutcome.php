<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

/**
 * Исход попытки выдать заказ.
 *
 * AlreadyDelivered — не ошибка, а нормальный результат повторного прогона:
 * задача идемпотентна по построению, и проигравший гонку воркер обязан
 * тихо выйти, а не откатывать чужую выдачу.
 */
enum DeliveryOutcome: string
{
    case Delivered = 'delivered';
    case AlreadyDelivered = 'already_delivered';
    case OutOfStock = 'out_of_stock';

    /** Аренду держит другой воркер — выходим тихо, это не ошибка. */
    case AlreadyInProgress = 'already_in_progress';

    /** Оплата отозвана до начала выдачи — товар отдавать нельзя. */
    case PaymentNotConfirmed = 'payment_not_confirmed';

    /** Поставщик доказанно не выдал код — можно пробовать следующего. */
    case SupplierExhausted = 'supplier_exhausted';

    /**
     * Судьба обращения к поставщику неизвестна. НЕ отказ: код мог быть выдан,
     * и уходить ко второму поставщику нельзя. Выясняется фоновым разрешением.
     */
    case AwaitingResolution = 'awaiting_resolution';

    /** Оба поставщика доказанно не выдали код — восстановимый отказ. */
    case DeliveryFailed = 'delivery_failed';

    case NotDeliverable = 'not_deliverable';
}
