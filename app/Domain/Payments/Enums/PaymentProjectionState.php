<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Проекция состояния оплаты, отделённая от FSM выполнения заказа.
 *
 * Ради этого разделения всё и затевалось: поздний failed обязан стать правдой
 * о деньгах, но не имеет права отменить уже отданный клиенту код. Доставка
 * ОБЯЗАНА читать эту проекцию после захвата аренды — без этой проверки поздний
 * failed отдаёт товар бесплатно и молча (CLAUDE.md §5.5).
 */
enum PaymentProjectionState: string
{
    case Paid = 'paid';
    case Failed = 'failed';

    public function allowsDelivery(): bool
    {
        return $this === self::Paid;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
