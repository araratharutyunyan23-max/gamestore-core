<?php

declare(strict_types=1);

namespace App\Domain\Delivery\RateLimit;

use App\Domain\Delivery\Enums\SupplierName;
use App\Support\Cfg;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Квота обращений к поставщику.
 *
 * Реализовано СКОЛЬЗЯЩИМ ОКНОМ, а не ведром токенов, и это не вкусовщина.
 * ТЗ говорит «лимит поставщика не превышается», то есть N обращений в ЛЮБУЮ
 * минуту. Ведро токенов такого не даёт: при ёмкости N и пополнении N в минуту
 * в окно шириной минуту помещается до 2N — полное ведро сразу плюс всё, что
 * накапало за окно. Фиксированное окно ломается так же на стыке двух окон.
 *
 * Скользящее окно хранит отметки времени обращений и потому отвечает на
 * вопрос точно. Шестьдесят чисел в отсортированном множестве стоят дёшево,
 * а гарантия получается та, которая заявлена, а не похожая на неё.
 *
 * Скрипт атомарный: проверка и запись обязаны быть одной операцией, иначе
 * два воркера, спросившие одновременно, оба получат разрешение на последний
 * оставшийся слот.
 */
final readonly class SupplierRateLimiter
{
    /**
     * KEYS[1] — множество отметок; ARGV: сейчас (мс), окно (мс), лимит, метка.
     *
     * Возвращает {разрешено, через сколько мс пробовать снова}.
     */
    private const ACQUIRE_LUA = <<<'LUA'
    local now = tonumber(ARGV[1])
    local window = tonumber(ARGV[2])
    local limit = tonumber(ARGV[3])

    redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, now - window)

    local used = redis.call('ZCARD', KEYS[1])

    if used >= limit then
        local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
        local freeAt = tonumber(oldest[2]) + window
        local wait = freeAt - now
        if wait < 1 then wait = 1 end
        return {0, wait}
    end

    redis.call('ZADD', KEYS[1], now, ARGV[4])
    redis.call('PEXPIRE', KEYS[1], window)
    return {1, 0}
    LUA;

    public function __construct(private RedisFactory $redis) {}

    /**
     * Занять слот под обращение к поставщику.
     *
     * Слот занимается ДО сетевого вызова и не возвращается, если вызов не
     * удался. Так и должно быть: поставщик считает попытки, а не успехи, и
     * неудачный запрос его квоту израсходовал ровно так же.
     */
    public function acquire(SupplierName $supplier, string $marker): RateLimitDecision
    {
        $limit = Cfg::supplierRateLimit();

        if ($limit <= 0) {
            // Лимит не задан — ограничивать нечего. Отдельная ветка, а не
            // «лимит = бесконечность»: обращение к Redis на каждой выдаче
            // там, где квоты нет, это цена без смысла.
            return RateLimitDecision::allowed();
        }

        $windowMs = Cfg::supplierRateWindowSeconds() * 1000;

        // Это EVAL РЕДИСА, а не eval() пыхи: на сервер уходит константный
        // скрипт, объявленный выше, а динамика — только в аргументах, которые
        // Redis передаёт скрипту как данные и никогда не исполняет. Иначе
        // «проверить и занять» невозможно сделать атомарно, а неатомарная
        // квота — это квота, которую два воркера превышают одновременно.
        //
        // Вызов идёт через command(), а не через ->eval(...), намеренно.
        // Laravel переставляет аргументы своего eval() в порядок
        // (скрипт, число ключей, ...остальное), а PHPStan типизирует метод по
        // сигнатуре phpredis (скрипт, массив, число ключей) — то есть код,
        // который проходит статанализ, падает в рантайме, и наоборот.
        // command() передаёт аргументы драйверу как есть и разрывает этот
        // спор: и анализатор, и Redis видят одно и то же.
        $result = $this->redis->connection()->command('eval', [
            self::ACQUIRE_LUA,
            [
                'supplier_rate:'.$supplier->value,
                (int) (microtime(true) * 1000),
                $windowMs,
                $limit,
                $marker,
            ],
            // Один ключ: остальное — аргументы. Разделение обязательно,
            // иначе Redis не сможет разложить операцию по слотам кластера.
            1,
        ]);

        if (! is_array($result)) {
            // Скрипт обязан вернуть пару. Не вернул — считаем, что квоты нет:
            // отказ безопаснее разрешения, потому что разрешение по ошибке
            // означает превышение лимита у поставщика.
            return RateLimitDecision::denied($windowMs);
        }

        $granted = $result[0] ?? 0;
        $retryAfter = $result[1] ?? $windowMs;

        return is_numeric($granted) && (int) $granted === 1
            ? RateLimitDecision::allowed()
            : RateLimitDecision::denied(is_numeric($retryAfter) ? (int) $retryAfter : $windowMs);
    }

    /**
     * Сколько обращений уже занято в текущем окне.
     *
     * Нужно для /metrics: без наблюдаемости «лимит не превышен» — утверждение,
     * которое нечем подтвердить (ТЗ 3.4).
     */
    public function used(SupplierName $supplier): int
    {
        $windowMs = Cfg::supplierRateWindowSeconds() * 1000;
        $now = (int) (microtime(true) * 1000);

        $connection = $this->redis->connection();
        $connection->zRemRangeByScore('supplier_rate:'.$supplier->value, '0', (string) ($now - $windowMs));

        $used = $connection->zCard('supplier_rate:'.$supplier->value);

        return is_numeric($used) ? (int) $used : 0;
    }
}
