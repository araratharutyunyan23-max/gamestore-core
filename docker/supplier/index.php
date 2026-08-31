<?php

declare(strict_types=1);

/**
 * Заглушка поставщика цифровых товаров.
 *
 * Один файл без фреймворка намеренно: это не часть системы, а стенд, и
 * проверяющий должен прочитать его целиком за минуту.
 *
 * КЛЮЧЕВОЕ СВОЙСТВО — claim-before-work. Запрос помечается как принятый
 * ДО любых задержек и до выдачи кода. Без этого probe, отправленный во время
 * обработки, ответил бы «такого запроса не знаю», клиент счёл бы это
 * доказательством «код не выдан» и ушёл ко второму поставщику — а первый
 * через секунду выдал бы код. Два кода на одну оплату.
 *
 * Поэтому состояний три, а не два: not_found, in_flight, issued. Плюс
 * четвёртое — sealed: обязательство НИКОГДА не выдавать код по этому
 * request_id. Только оно (или доказанный отказ) даёт клиенту право уйти
 * на второго поставщика.
 *
 * Стор переживает перезапуск (AOF + volume): иначе повтор с тем же
 * request_id выдал бы второй код и сломал контракт идемпотентности
 * на ровном месте.
 */
$redis = new Redis;
// Порт берётся из окружения, а не зашивается: в docker-сети Redis слушает
// стандартный 6379, а в CI это сервисный контейнер, опубликованный на другом
// порту. Захардкоженный порт давал зелёный прогон локально и красный в CI.
$redis->connect(
    (string) (getenv('SUPPLIER_REDIS_HOST') ?: 'supplier-redis'),
    (int) (getenv('SUPPLIER_REDIS_PORT') ?: 6379),
);

$name = (string) (getenv('SUPPLIER_NAME') ?: 'A');
$prefix = "sup:{$name}:";

// Эпоха стора. Меняется при потере данных — клиент по ней понимает, что
// ответ probe уже не авторитетен, и не снимает замок на fallback.
$epoch = $redis->get($prefix.'epoch');

if (! is_string($epoch)) {
    $epoch = bin2hex(random_bytes(8));
    $redis->set($prefix.'epoch', $epoch);
}

header('Content-Type: application/json');
header('X-Store-Epoch: '.$epoch);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/';
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

/** @param array<string, mixed> $payload */
function reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function str_field(mixed $value, string $fallback = ''): string
{
    return is_string($value) && $value !== '' ? $value : $fallback;
}

/*
 * Ниже три скрипта для Redis::eval — это Lua на стороне Redis, а не PHP-eval.
 * Тексты скриптов — константы; данные приходят только через KEYS и ARGV,
 * то есть параметризованно, и подстановки в код нет. Lua нужен потому, что
 * проверка состояния и его смена обязаны быть атомарными: между HGET и HSET
 * из PHP помещается конкурент, и весь смысл claim-before-work теряется.
 */

/**
 * Первый шаг выдачи: пометить запрос принятым.
 *
 * Атомарно и ДО любых задержек — иначе окно между «запрос пришёл» и
 * «запрос виден probe» становится источником двойной выдачи.
 */
const CLAIM_LUA = <<<'LUA'
local state = redis.call('HGET', KEYS[1], 'state')
if state == 'issued' then return {'issued', redis.call('HGET', KEYS[1], 'code')} end
if state == 'sealed' then return {'sealed', ''} end
if state == 'in_flight' then return {'in_flight', ''} end
redis.call('HSET', KEYS[1], 'state', 'in_flight', 'claimed_at', ARGV[1], 'order_id', ARGV[2])
redis.call('EXPIRE', KEYS[1], 604800)
return {'claimed', ''}
LUA;

/**
 * Второй шаг: выдать код. Повтор с тем же request_id возвращает ТОТ ЖЕ код —
 * это и есть контрактная идемпотентность поставщика.
 */
const ISSUE_LUA = <<<'LUA'
local state = redis.call('HGET', KEYS[1], 'state')
if state == 'issued' then return {'replay', redis.call('HGET', KEYS[1], 'code')} end
if state == 'sealed' then return {'sealed', ''} end
local code = redis.call('LPOP', KEYS[2])
if not code then return {'out_of_stock', ''} end
redis.call('HSET', KEYS[1], 'state', 'issued', 'code', code, 'sku', ARGV[1])
redis.call('RPUSH', KEYS[3], ARGV[2])
return {'issued', code}
LUA;

/**
 * Печать: not_found -> sealed. Обязательство никогда не выдавать код
 * по этому request_id. in_flight запечатать нельзя — обработка идёт.
 */
const SEAL_LUA = <<<'LUA'
local state = redis.call('HGET', KEYS[1], 'state')
if state == 'issued' then return {'issued', redis.call('HGET', KEYS[1], 'code')} end
if state == 'in_flight' then return {'in_flight', ''} end
if state == 'sealed' then return {'sealed', ''} end
redis.call('HSET', KEYS[1], 'state', 'sealed')
redis.call('EXPIRE', KEYS[1], 604800)
return {'sealed', ''}
LUA;

$ridKey = static fn (string $rid): string => $prefix.'rid:'.$rid;

// --- Управляющий контур для тестов -----------------------------------------
// Вероятности из переменных окружения остаются поведением по умолчанию для
// демонстрации, но тест на вероятности — это не тест. Сценарии задаются
// предписанно, и тогда они воспроизводятся со стопроцентной повторяемостью.

if ($method === 'PUT' && $path === '/admin/behavior') {
    $redis->hMSet($prefix.'behavior', [
        'mode' => str_field($body['mode'] ?? null, 'ok'),
        'times' => (string) (int) ($body['times'] ?? 1),
        'delay_ms' => (string) (int) ($body['delay_ms'] ?? 0),
    ]);

    reply(200, ['status' => 'ok']);
}

if ($method === 'PUT' && preg_match('#^/admin/stock/([\w-]+)$#', $path, $m) === 1) {
    $sku = $m[1];
    $redis->del($prefix.'pool:'.$sku);
    $qty = (int) ($body['qty'] ?? 0);

    for ($i = 0; $i < $qty; $i++) {
        $redis->rPush($prefix.'pool:'.$sku, sprintf('%s-%s-%04d', strtoupper($name), substr($sku, 0, 4), $i));
    }

    reply(200, ['status' => 'ok', 'sku' => $sku, 'qty' => $qty]);
}

if ($method === 'POST' && $path === '/admin/reset') {
    $keys = $redis->keys($prefix.'*');

    if (is_array($keys) && $keys !== []) {
        $redis->del($keys);
    }

    $redis->set($prefix.'epoch', bin2hex(random_bytes(8)));

    reply(200, ['status' => 'ok']);
}

// Полный перечень обязательств по заказу — инструмент сверки этапа 4
// и восстановления: заглушки пишем мы, значит имеем право расширить контракт.
if ($method === 'GET' && $path === '/issues') {
    $orderId = str_field($_GET['order_id'] ?? null);
    $issued = $redis->lRange($prefix.'issued', 0, -1);
    $result = [];

    foreach (is_array($issued) ? $issued : [] as $rid) {
        $row = $redis->hGetAll($ridKey((string) $rid));

        if (is_array($row) && ($orderId === '' || ($row['order_id'] ?? null) === $orderId)) {
            $result[] = ['request_id' => $rid, 'state' => $row['state'] ?? null, 'order_id' => $row['order_id'] ?? null];
        }
    }

    reply(200, ['status' => 'ok', 'issues' => $result]);
}

// --- Основной контракт ------------------------------------------------------

if ($method === 'GET' && preg_match('#^/issue/([\w.-]+)$#', $path, $m) === 1) {
    $row = $redis->hGetAll($ridKey($m[1]));
    $state = is_array($row) ? ($row['state'] ?? null) : null;

    // Четыре ответа вместо двух. not_found НЕ означает «не выдам» — только
    // «пока не выдал»; право на fallback даёт лишь sealed.
    reply(match ($state) {
        'issued' => 200,
        'in_flight' => 200,
        'sealed' => 409,
        default => 404,
    }, match ($state) {
        'issued' => ['status' => 'issued', 'request_id' => $m[1], 'code' => $row['code'] ?? null],
        'in_flight' => ['status' => 'in_flight', 'request_id' => $m[1]],
        'sealed' => ['status' => 'sealed', 'request_id' => $m[1]],
        default => ['status' => 'not_found', 'request_id' => $m[1]],
    });
}

if ($method === 'POST' && preg_match('#^/issue/([\w.-]+)/seal$#', $path, $m) === 1) {
    /** @var array{0: string, 1: string} $result */
    $result = $redis->eval(SEAL_LUA, [$ridKey($m[1])], 1);

    reply(match ($result[0]) {
        'issued' => 200,
        'in_flight' => 409,
        default => 200,
    }, match ($result[0]) {
        'issued' => ['status' => 'issued', 'request_id' => $m[1], 'code' => $result[1]],
        'in_flight' => ['status' => 'in_flight', 'request_id' => $m[1]],
        default => ['status' => 'sealed', 'request_id' => $m[1]],
    });
}

if ($method === 'POST' && $path === '/issue') {
    $requestId = str_field($body['request_id'] ?? null);
    $sku = str_field($body['sku'] ?? null);
    $orderId = str_field($body['order_id'] ?? null);

    if ($requestId === '' || $sku === '') {
        reply(400, ['status' => 'error', 'reason' => 'invalid_request']);
    }

    // Шаг 1: пометить запрос принятым. Только после этого — задержки и сбои.
    /** @var array{0: string, 1: string} $claim */
    $claim = $redis->eval(CLAIM_LUA, [$ridKey($requestId), (string) time(), $orderId], 1);

    if ($claim[0] === 'issued') {
        reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $claim[1]]);
    }

    if ($claim[0] === 'sealed') {
        reply(409, ['status' => 'error', 'reason' => 'sealed']);
    }

    if ($claim[0] === 'in_flight') {
        reply(409, ['status' => 'error', 'reason' => 'in_flight']);
    }

    $behavior = $redis->hGetAll($prefix.'behavior');
    $behavior = is_array($behavior) ? $behavior : [];
    $mode = str_field($behavior['mode'] ?? null, defaultMode());
    $left = (int) ($behavior['times'] ?? 0);

    if ($left > 0) {
        $redis->hSet($prefix.'behavior', 'times', (string) ($left - 1));
    } else {
        $mode = defaultMode();
    }

    $delayMs = (int) ($behavior['delay_ms'] ?? 0);

    if ($mode === 'error') {
        // Захват ОБЯЗАН быть отпущен: запрос не привёл к выдаче, и поставщик
        // о нём больше ничего не знает. Оставленный «в работе» захват делает
        // печать невозможной навсегда — probe вечно отвечает in_flight,
        // клиент не может уйти ко второму поставщику, и заказ висит.
        // Реальный поставщик, вернувший 500 без выдачи, на probe тоже
        // ответил бы «такого запроса не знаю».
        $redis->del($ridKey($requestId));
        reply(500, ['status' => 'error', 'reason' => 'internal']);
    }

    if ($mode === 'timeout') {
        // Код НЕ выдан, ответа нет. Захват при этом НЕ отпускается, и это
        // правильно: поставщик «думает», клиент не знает исхода и обязан
        // выяснять его через probe и печать, а не гадать.
        sleep(30);
        reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => 'UNREACHABLE']);
    }

    if ($mode === 'slow') {
        usleep($delayMs * 1000);
    }

    /** @var array{0: string, 1: string} $issue */
    $issue = $redis->eval(ISSUE_LUA, [$ridKey($requestId), $prefix.'pool:'.$sku, $prefix.'issued', $sku, $requestId], 3);

    if ($issue[0] === 'out_of_stock') {
        // Тот же случай: выдачи не было, захват отпускается.
        $redis->del($ridKey($requestId));

        // Авторитетный отказ: код точно не выдан. Это единственный класс
        // ответа, который сразу разрешает уход ко второму поставщику.
        reply(409, ['status' => 'error', 'reason' => 'out_of_stock']);
    }

    if ($issue[0] === 'sealed') {
        reply(409, ['status' => 'error', 'reason' => 'sealed']);
    }

    if ($mode === 'timeout_after_issue') {
        // ЛОВУШКА ЗАДАНИЯ. Код уже выдан и списан, но ответ не дойдёт.
        // Повтор с тем же request_id обязан вернуть тот же код, а не второй.
        sleep(30);
    }

    reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $issue[1]]);
}

reply(404, ['status' => 'error', 'reason' => 'unknown_endpoint']);

/**
 * Поведение по умолчанию: доли сбоев из окружения, как требует задание.
 * Используется, только когда предписанный режим не задан или исчерпан.
 */
function defaultMode(): string
{
    $errorRate = (int) (getenv('SUPPLIER_ERROR_RATE') ?: 0);
    $timeoutRate = (int) (getenv('SUPPLIER_TIMEOUT_RATE') ?: 0);
    $roll = random_int(1, 100);

    if ($roll <= $errorRate) {
        return 'error';
    }

    if ($roll <= $errorRate + $timeoutRate) {
        return 'timeout';
    }

    return 'ok';
}
