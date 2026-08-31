<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * Состояние строки delivery_attempts. Значения совпадают с
 * delivery_attempts_outcome_chk; за этим следит тест-страж.
 */
enum AttemptOutcome: string
{
    /** Строка записана ДО HTTP-вызова. Это журнал намерения, а не результата. */
    case InFlight = 'in_flight';

    case Succeeded = 'succeeded';

    /** Доказанный отказ: поставщик точно ничего не выдал. */
    case Failed = 'failed';

    case Timeout = 'timeout';

    /** Судьба неизвестна. Не отказ. Уходить на другого поставщика запрещено. */
    case Unknown = 'unknown';

    /** Поставщик обязался не выдавать код по этому request_id. Замок снят. */
    case Sealed = 'sealed';

    /** Бюджет разрешения исчерпан, обязательство закрыто вручную/по политике. */
    case Abandoned = 'abandoned';

    /** Код пришёл, но заказ уже закрыт другим кодом: сурплус, требует учёта. */
    case Superseded = 'superseded';

    /**
     * Открытая попытка блокирует вторую по тому же заказу на уровне БД
     * (delivery_attempts_one_open_uq). Это и есть физическая защита от того,
     * что таймаут будет принят за отказ.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::InFlight, self::Timeout, self::Unknown => true,
            self::Succeeded, self::Failed, self::Sealed,
            self::Abandoned, self::Superseded => false,
        };
    }

    public function isResolved(): bool
    {
        return ! $this->isOpen();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $outcome): string => $outcome->value, self::cases());
    }
}
