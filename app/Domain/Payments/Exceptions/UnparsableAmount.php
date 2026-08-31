<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use DomainException;

final class UnparsableAmount extends DomainException
{
    public static function float(float $value): self
    {
        return new self("Amount arrived as float ({$value}); money must never be a float");
    }

    public static function value(mixed $value): self
    {
        return new self('Amount is not a valid decimal string: '.get_debug_type($value));
    }
}
