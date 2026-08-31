<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * Судьба кода, полученного от поставщика. Код персистится ДО того, как станет
 * известна его судьба, поэтому Unassigned — легальное промежуточное состояние,
 * а не дефект (CLAUDE.md §5.2).
 */
enum CodeDisposition: string
{
    case Unassigned = 'unassigned';
    case ForOrder = 'for_order';
    case Surplus = 'surplus';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
