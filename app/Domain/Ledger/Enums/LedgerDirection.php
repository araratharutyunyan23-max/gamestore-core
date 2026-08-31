<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }

    /** Знак, которым сумма входит в проверку баланса. Совпадает с amount_signed в БД. */
    public function sign(): int
    {
        return match ($this) {
            self::Debit => 1,
            self::Credit => -1,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
