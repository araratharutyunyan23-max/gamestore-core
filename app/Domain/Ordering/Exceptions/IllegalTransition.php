<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\StateMachine\TransitionResult;
use DomainException;

final class IllegalTransition extends DomainException
{
    public static function from(
        string $publicId,
        OrderStatus $from,
        OrderStatus $to,
        TransitionResult $result,
    ): self {
        return new self(sprintf(
            'Order %s: transition %s -> %s refused (%s)',
            $publicId,
            $from->value,
            $to->value,
            $result->value,
        ));
    }
}
