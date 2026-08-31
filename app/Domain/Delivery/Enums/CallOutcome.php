<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * Классификация исхода вызова поставщика.
 *
 * Это центральное место всего этапа. Исход определяется по паре
 * (фаза транспорта, разобранный конверт поставщика), а НЕ по HTTP-коду:
 * 5xx может прилететь от балансировщика уже после того, как код закоммичен,
 * а пустое тело не говорит о побочном эффекте ничего.
 *
 * Классическая схема «4xx не ретраить, 5xx ретраить, таймаут = ошибка» молча
 * приравнивает неизвестность к отказу, разрешает уход ко второму поставщику
 * после таймаута и ломает критерий приёмки №4 в первый же прогон.
 */
enum CallOutcome: string
{
    /** Код получен. */
    case Issued = 'issued';

    /**
     * ДОКАЗАНО, что выдачи не было: конверт с бизнес-причиной (out_of_stock),
     * либо запрос физически не ушёл — соединение не установилось.
     */
    case NotIssuedCertain = 'not_issued_certain';

    /** Поставщик обязался никогда не выдавать код по этому request_id. */
    case Sealed = 'sealed';

    /** Запрос у поставщика в работе. Уходить ко второму нельзя. */
    case InFlight = 'in_flight';

    /** Поставщик о запросе не знает. Это «пока не выдал», а не «не выдам». */
    case NotFound = 'not_found';

    /**
     * Таймаут чтения, обрыв после отправки, 5xx без конверта, битое тело.
     * Судьба кода неизвестна.
     */
    case Unknown = 'unknown';

    /**
     * Единственное место, где вычисляется право уйти ко второму поставщику.
     *
     * NotFound здесь СОЗНАТЕЛЬНО отсутствует. Probe может обогнать ещё живой
     * POST и ответить «не знаю такого» за миллисекунды до того, как код будет
     * выдан. Право даёт только доказанное отсутствие выдачи или печать.
     */
    public function unblocksFallback(): bool
    {
        return match ($this) {
            self::NotIssuedCertain, self::Sealed => true,
            self::Issued, self::InFlight, self::NotFound, self::Unknown => false,
        };
    }

    /** Исход, после которого попытка считается закрытой и больше не мешает. */
    public function isResolved(): bool
    {
        return match ($this) {
            self::Issued, self::NotIssuedCertain, self::Sealed => true,
            self::InFlight, self::NotFound, self::Unknown => false,
        };
    }

    public function toAttemptOutcome(): AttemptOutcome
    {
        return match ($this) {
            self::Issued => AttemptOutcome::Succeeded,
            self::NotIssuedCertain => AttemptOutcome::Failed,
            self::Sealed => AttemptOutcome::Sealed,
            self::InFlight, self::NotFound, self::Unknown => AttemptOutcome::Unknown,
        };
    }
}
