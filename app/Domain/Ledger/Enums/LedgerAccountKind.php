<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerAccountKind: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
