<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Что мы сделали с принятым событием.
 *
 * Инбокс — единственный источник истины для восстановления: подметальщик берёт
 * работу отсюда, а не от статуса заказа. Заказ в created с потерянным платежом
 * не виден ни одному статусному фильтру, а строке с applied_at IS NULL — виден.
 */
enum PaymentEventState: string
{
    case Pending = 'pending';
    case Applied = 'applied';

    /** Тот же event_id, то же тело — честный повтор at-least-once. */
    case DuplicateEvent = 'duplicate_event';

    /**
     * Другой event_id, но заказ уже оплачен. Деньги могли реально прийти дважды,
     * поэтому это не «ничего не делаем», а отдельная проводка в suspense и аномалия.
     */
    case DuplicatePaid = 'duplicate_paid';

    /** Событие старше уже применённого — отбрасывается по кортежу монотонности. */
    case Stale = 'stale';

    /** Вебхук пришёл раньше заказа. Штатный сценарий, не ошибка. */
    case OrderMissing = 'order_missing';

    case AmountMismatch = 'amount_mismatch';

    /** Заказ уже в финальном статусе — выдачу не трогаем, факт фиксируем. */
    case IgnoredFinal = 'ignored_final';

    /** Тело не разобрано. Отвечаем 200, чтобы платёжка не ретраила вечно. */
    case Malformed = 'malformed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
