<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Откуда берётся код.
 *
 * Pool — из собственного пула ключей, выдача целиком в одной транзакции.
 * Supplier — из внешнего поставщика, значит сетевой вызов, значит ловушка
 * таймаута и совсем другой порядок фиксации (CLAUDE.md §5.2).
 */
enum SupplyMode: string
{
    case Pool = 'pool';
    case Supplier = 'supplier';

    public function requiresNetworkCall(): bool
    {
        return $this === self::Supplier;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
