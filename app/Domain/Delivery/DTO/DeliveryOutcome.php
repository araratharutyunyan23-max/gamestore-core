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

    /** Оплата отозвана до начала выдачи — товар отдавать нельзя. */
    case PaymentNotConfirmed = 'payment_not_confirmed';

    /** Товар от внешнего поставщика: сетевой путь появляется на шаге Ш4. */
    case SupplierNotImplemented = 'supplier_not_implemented';

    case NotDeliverable = 'not_deliverable';
}
