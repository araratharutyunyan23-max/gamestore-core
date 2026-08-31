<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * Классификация исхода вызова поставщика.
 *
 * Это центральное место ловушки таймаута. Исход определяется по паре
 * (фаза транспорта, разобранный конверт поставщика), а НЕ по HTTP-коду:
 * 5xx может прилететь от балансировщика уже после того, как код закоммичен,
 * а пустое тело не говорит о побочном эффекте ничего.
 *
 * Классическая схема «4xx не ретраить, 5xx ретраить, таймаут = ошибка» молча
 * приравнивает неизвестность к отказу, разрешает уход на второго поставщика
 * после таймаута и ломает критерий приёмки №4 в первый же прогон.
 */
enum CallOutcome: string
{
    /** Поставщик вернул код. */
    case Issued = 'issued';

    /**
     * ДОКАЗАНО, что выдачи не было: конверт status=error с бизнес-причиной,
     * либо запрос физически не ушёл (connection refused, DNS, TLS-handshake).
     */
    case NotIssuedCertain = 'not_issued_certain';

    /**
     * Поставщик заявил ошибку, но доказательства нет: 5xx/429 с конвертом
     * internal|rate_limited. В отказ напрямую не ведёт — только в Unknown.
     */
    case NotIssuedClaimed = 'not_issued_claimed';

    /** Probe сказал «такого запроса не знаю» — но он мог просто не успеть. */
    case ProbeNotFound = 'probe_not_found';

    /** Probe сказал «запрос у меня в работе». Уходить на B нельзя. */
    case ProbeInFlight = 'probe_in_flight';

    /** Seal удался: поставщик обязался НИКОГДА не выдавать код по этому request_id. */
    case Sealed = 'sealed';

    /** Read timeout, обрыв после отправки, невалидное тело, любой 5xx без конверта. */
    case Unknown = 'unknown';

    /**
     * Единственное место, где вычисляется право уйти на второго поставщика.
     *
     * probe -> 404 сам по себе замок НЕ снимает: GET может гоняться с ещё живым
     * POST'ом и «доказать», что кода нет, за миллисекунды до того, как он выдан.
     * Право даёт только доказанное отсутствие выдачи или успешный seal.
     */
    public function unblocksFallback(): bool
    {
        return match ($this) {
            self::NotIssuedCertain, self::Sealed => true,
            self::Issued, self::NotIssuedClaimed, self::ProbeNotFound,
            self::ProbeInFlight, self::Unknown => false,
        };
    }

    /**
     * Разрешён ли инкремент эпохи. Новая эпоха = новый request_id = право
     * получить новый код. Пока судьба предыдущего вызова неизвестна, это запрещено.
     */
    public function allowsEpochIncrement(): bool
    {
        return $this->unblocksFallback();
    }

    public function toAttemptOutcome(): AttemptOutcome
    {
        return match ($this) {
            self::Issued => AttemptOutcome::Succeeded,
            self::NotIssuedCertain => AttemptOutcome::Failed,
            self::Sealed => AttemptOutcome::Sealed,
            self::NotIssuedClaimed, self::ProbeNotFound,
            self::ProbeInFlight, self::Unknown => AttemptOutcome::Unknown,
        };
    }
}
