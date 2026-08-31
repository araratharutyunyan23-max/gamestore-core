<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use DomainException;

final class UnparsableAmount extends DomainException
{
    public static function float(float $value): self
    {
        return new self("Amount {$value} is not exactly representable in kopecks");
    }

    public static function value(mixed $value): self
    {
        return new self('Amount is not a valid decimal string: '.get_debug_type($value));
    }
}
