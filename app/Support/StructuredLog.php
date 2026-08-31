<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\Enums\SupplierName;
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

    /**
     * Причина передаётся отдельным параметром, а не вместо заказа.
     *
     * Раньше параметра не было, и текст исключения из payment_apply_failed
     * подставлялся третьим аргументом — то есть попадал в поле order_id.
     * Поиск по order_id — основной способ найти историю заказа, и такая
     * запись ломала ровно тот сценарий, ради которого лог и пишется:
     * упавшее событие нельзя было найти по заказу, а по order_id
     * возвращался мусор.
     */
    public static function webhook(
        string $event,
        string $eventId,
        ?string $orderPublicId = null,
        ?string $reason = null,
    ): void {
        self::write($event, array_filter([
            'event_id' => $eventId,
            'order_id' => $orderPublicId,
            'reason' => $reason,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
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
     * Путь поставщика — единственное место, где нужны все поля §7 сразу.
     *
     * До этого метода supplier_* писались через delivery(), и в единственное
     * поле reason сваливалось всё подряд: то request_id, то outcome, то
     * строка вида «a->b». По такому логу нельзя ни сгруппировать по
     * поставщику, ни посчитать долю таймаутов, ни увидеть латентность —
     * то есть нельзя ответить ни на один вопрос, ради которого лог и пишут.
     *
     * Поля разнесены, и каждое значит ровно одно.
     */
    public static function supplier(
        string $event,
        string $orderPublicId,
        SupplierName $supplier,
        RequestId $requestId,
        ?string $outcome = null,
        ?int $latencyMs = null,
        ?string $codeLast4 = null,
        ?string $reason = null,
    ): void {
        self::write($event, array_filter([
            'order_id' => $orderPublicId,
            'supplier' => $supplier->value,
            'request_id' => $requestId->value,
            // Номер попытки — это эпоха: она растёт только после доказанного
            // «не выдано», поэтому по ней видно, сколько РАЗНЫХ обращений
            // к поставщику пережил заказ, а не сколько было сетевых ретраев.
            'attempt' => $requestId->epoch,
            'outcome' => $outcome,
            'latency_ms' => $latencyMs,
            'code_last4' => $codeLast4,
            'reason' => $reason,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== ''));
    }

    /**
     * Находка сверки.
     *
     * Сверка писала аномалии только в свою таблицу — то есть увидеть их можно
     * было, лишь зная, что надо посмотреть. Расхождение в деньгах обязано
     * доходить до общего потока логов: там на него настроен алерт, там его
     * увидят без отдельного ритуала.
     */
    public static function finding(string $kind, string $severity, string $subject): void
    {
        self::write('reconciliation_finding', [
            'reason' => $kind,
            'outcome' => $severity,
            'order_id' => $subject,
        ]);
    }

    /**
     * @param  array<string, scalar>  $context
     */
    private static function write(string $event, array $context): void
    {
        Log::info($event, ['trace_id' => self::traceId(), 'event' => $event] + $context);
    }
}
