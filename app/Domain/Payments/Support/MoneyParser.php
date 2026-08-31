<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Exceptions\UnparsableAmount;

/**
 * Единственное место, где сумма из внешнего мира превращается в копейки.
 *
 * ТЗ не определяет однозначно, в каких единицах приходит amount: пример
 * {"amount": 500} для заказа на 500 ₽ читается как рубли, но многие платёжные
 * системы шлют копейки. Допущение — МАЖОРНЫЕ единицы, и оно изолировано здесь,
 * потому что ошибка тут даёт расхождение в 100 раз и блокирует все выдачи.
 *
 * Принимает mixed намеренно: тело вебхука — это JSON из внешнего мира.
 * Строгая сигнатура int|string при strict_types дала бы TypeError, то есть
 * 500 в ответ, то есть вечные ретраи платёжной системы.
 */
final class MoneyParser
{
    /**
     * @throws UnparsableAmount
     */
    public static function majorToMinor(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw * 100;
        }

        // float отбивается явно: 0.1 + 0.2 в деньгах недопустимо ни в каком виде.
        if (is_float($raw)) {
            throw UnparsableAmount::float($raw);
        }

        if (! is_string($raw) || preg_match('/^-?\d+(\.\d{1,2})?$/', $raw) !== 1) {
            throw UnparsableAmount::value($raw);
        }

        $parts = explode('.', $raw, 2);
        $major = (int) $parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $sign = $major < 0 || str_starts_with($raw, '-') ? -1 : 1;

        return $sign * (abs($major) * 100 + (int) $fraction);
    }
}
