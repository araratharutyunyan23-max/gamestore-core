<?php

declare(strict_types=1);

namespace App\Domain\Ordering\StateMachine;

/**
 * Исход попытки перевести заказ в новый статус.
 *
 * Событийные пути (вебхук, джоба) обязаны получать ЭТО, а не исключение:
 * проигранная гонка и повторная доставка того же события — штатные ситуации,
 * а не сбои. Джоба, падающая на них, отправляется в ретрай и порождает
 * новую волну конкурентов.
 */
enum TransitionResult: string
{
    case Applied = 'applied';

    /** Кто-то другой уже перевёл заказ. Это успех соседа, а не наша ошибка. */
    case LostRace = 'lost_race';

    /** Заказ уже финальный — трогать нельзя, и триггер БД это подтвердит. */
    case IgnoredFinal = 'ignored_final';

    /** Переход запрещён машиной состояний. */
    case IgnoredIllegal = 'ignored_illegal';

    public function changedAnything(): bool
    {
        return $this === self::Applied;
    }
}
