<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/** Статус из контракта вебхука. Unknown — для тел, которые мы не смогли разобрать. */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
