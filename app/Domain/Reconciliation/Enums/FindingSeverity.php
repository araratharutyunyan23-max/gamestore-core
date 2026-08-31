<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Enums;

enum FindingSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';

    /** Только critical влияет на здоровье системы и на код ответа сверки. */
    public function affectsHealth(): bool
    {
        return $this === self::Critical;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
