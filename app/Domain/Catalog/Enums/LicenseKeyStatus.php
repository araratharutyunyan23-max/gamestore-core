<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum LicenseKeyStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Issued = 'issued';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
