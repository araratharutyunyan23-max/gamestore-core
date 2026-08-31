<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Ключ идемпотентности имеет жизненный цикл, а не только факт существования.
 *
 * «Захват без завершения» — классическая ловушка: строка вставлена, воркер убит,
 * и повтор навсегда видит «уже занято», трактуя это как «уже сделано». Отсюда
 * два состояния и перехват протухшего захвата (CLAUDE.md §5.4).
 */
enum IdempotencyState: string
{
    case Claimed = 'claimed';
    case Completed = 'completed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
