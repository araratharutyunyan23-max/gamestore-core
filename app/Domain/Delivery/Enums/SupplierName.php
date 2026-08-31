<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

enum SupplierName: string
{
    case A = 'A';
    case B = 'B';

    /** Основной поставщик; второй берётся только по правилам fallback. */
    public static function primary(): self
    {
        return self::A;
    }

    public function fallback(): ?self
    {
        return match ($this) {
            self::A => self::B,
            self::B => null,
        };
    }

    public function configKey(): string
    {
        return 'suppliers.'.strtolower($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $name): string => $name->value, self::cases());
    }
}
