<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Структурные логи платёжного и выдачного пути.
 *
 * Единая точка по двум причинам. Первая — набор полей обязан быть одинаковым
 * во всех событиях, иначе по логам нельзя восстановить путь заказа. Вторая —
 * сюда физически нельзя передать код ключа: методы принимают только те поля,
 * которые перечислены явно, и тест проверяет, что кода в логах нет.
 */
final class StructuredLog
{
    /**
     * Идентификатор сквозного пути берётся из Context, а не из статического
     * свойства класса.
     *
     * Статика здесь была прямой ошибкой: воркер очереди живёт до часа и
     * обрабатывает сотни задач, а статическое свойство между ними не
     * сбрасывается — первая задача зафиксировала бы ULID, и весь дальнейший
     * лог писался бы с одним trace_id. Наблюдаемость, ради которой класс и
     * существует, при этом не работает вовсе.
     *
     * Context сбрасывается фреймворком между задачами и переносится в очередь
     * вместе с задачей, поэтому вебхук и применение платежа связываются
     * одним идентификатором.
     */
    public static function traceId(): string
    {
        $existing = Context::get('trace_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $traceId = (string) Str::ulid();
        Context::add('trace_id', $traceId);

        return $traceId;
    }

    public static function webhook(string $event, string $eventId, string $orderPublicId): void
    {
        self::write($event, [
            'event_id' => $eventId,
            'order_id' => $orderPublicId,
        ]);
    }

    public static function payment(string $event, string $orderPublicId, string $statusFrom, string $statusTo): void
    {
        self::write($event, [
            'order_id' => $orderPublicId,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
        ]);
    }

    /**
     * Код никогда не логируется целиком — только последние четыре символа,
     * достаточные для поддержки и бесполезные для кражи.
     */
    public static function delivery(string $event, string $orderPublicId, ?string $codeLast4 = null, ?string $reason = null): void
    {
        self::write($event, array_filter([
            'order_id' => $orderPublicId,
            'code_last4' => $codeLast4,
            'reason' => $reason,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @param  array<string, scalar>  $context
     */
    private static function write(string $event, array $context): void
    {
        Log::info($event, ['trace_id' => self::traceId(), 'event' => $event] + $context);
    }
}
