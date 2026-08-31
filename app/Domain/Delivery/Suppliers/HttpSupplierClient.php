<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\DTO\IssueRequest;
use App\Domain\Delivery\DTO\SupplierResponse;
use App\Domain\Delivery\Enums\CallOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Клиент поставщика. Один класс на обоих: A и B отличаются только конфигурацией.
 *
 * Ни один метод не бросает исключений. Сетевой сбой — это тоже исход, и он
 * обязан прийти значением: исключение заставило бы вызывающий код угадывать,
 * выдан код или нет, а угадывание здесь стоит второго кода на одну оплату.
 */
final readonly class HttpSupplierClient implements SupplierGateway
{
    public function __construct(
        private SupplierName $supplier,
        private string $baseUrl,
        private float $connectTimeout,
        private float $issueTimeout,
    ) {}

    public function name(): SupplierName
    {
        return $this->supplier;
    }

    public function issue(IssueRequest $request): SupplierResponse
    {
        return $this->call('POST', '/issue', $request->toPayload());
    }

    /**
     * Неизменяющий вопрос «что с этим запросом».
     *
     * Отдельный метод, а не повтор POST: повтор не умеет ответить «я ничего
     * не выдавал» — он в этом случае выдаст код прямо сейчас. Без probe из
     * неизвестности нет выхода, кроме как ждать вечно.
     */
    public function probe(string $requestId): SupplierResponse
    {
        return $this->call('GET', '/issue/'.$requestId);
    }

    /**
     * Печать: попросить поставщика обязаться НИКОГДА не выдавать код по этому
     * request_id. Успешная печать — единственное, что превращает неизвестность
     * в право уйти ко второму поставщику.
     */
    public function seal(string $requestId): SupplierResponse
    {
        return $this->call('POST', '/issue/'.$requestId.'/seal');
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function call(string $method, string $path, array $payload = []): SupplierResponse
    {
        $startedAt = microtime(true);

        try {
            $request = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->issueTimeout);

            $response = $method === 'GET'
                ? $request->get($this->baseUrl.$path)
                : $request->post($this->baseUrl.$path, $payload);

            return $this->classify($response, $this->elapsedMs($startedAt));
        } catch (ConnectionException $e) {
            return $this->classifyTransportFailure($e, $this->elapsedMs($startedAt));
        } catch (Throwable $e) {
            // Любая неожиданность — тоже неизвестность, а не отказ.
            return new SupplierResponse(
                outcome: CallOutcome::Unknown,
                errorKind: 'unexpected:'.$e::class,
                latencyMs: $this->elapsedMs($startedAt),
            );
        }
    }

    private function classify(Response $response, int $latencyMs): SupplierResponse
    {
        $status = $response->status();
        $epoch = $response->header('X-Store-Epoch');
        $epoch = $epoch === '' ? null : $epoch;

        $envelope = $response->json();
        $envelope = is_array($envelope) ? $envelope : [];
        $kind = $this->stringOrNull($envelope['status'] ?? null);
        $reason = $this->stringOrNull($envelope['reason'] ?? null);
        $code = $this->stringOrNull($envelope['code'] ?? null);

        // Успех и выдача.
        if ($status === 200 && ($kind === 'ok' || $kind === 'issued') && $code !== null) {
            return new SupplierResponse(CallOutcome::Issued, $code, $status, null, $epoch, $latencyMs);
        }

        if ($status === 200 && $kind === 'in_flight') {
            return new SupplierResponse(CallOutcome::InFlight, null, $status, 'in_flight', $epoch, $latencyMs);
        }

        if ($kind === 'sealed' || $reason === 'sealed') {
            return new SupplierResponse(CallOutcome::Sealed, null, $status, 'sealed', $epoch, $latencyMs);
        }

        if ($status === 409 && $reason === 'in_flight') {
            return new SupplierResponse(CallOutcome::InFlight, null, $status, 'in_flight', $epoch, $latencyMs);
        }

        if ($status === 404 && $kind === 'not_found') {
            return new SupplierResponse(CallOutcome::NotFound, null, $status, 'not_found', $epoch, $latencyMs);
        }

        // Авторитетный отказ: поставщик прислал РАЗОБРАННЫЙ конверт с бизнес-
        // причиной. Только это доказывает, что код не выдан.
        if ($status >= 400 && $status < 500 && $kind === 'error' && $reason !== null) {
            return new SupplierResponse(CallOutcome::NotIssuedCertain, null, $status, $reason, $epoch, $latencyMs);
        }

        // Всё остальное, включая ЛЮБОЙ 5xx: 500 мог прийти от прокси уже после
        // того, как код закоммичен. Побочный эффект неизвестен.
        return new SupplierResponse(
            outcome: CallOutcome::Unknown,
            httpStatus: $status,
            errorKind: $reason ?? 'unparsable_body',
            storeEpoch: $epoch,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Различение «запрос не ушёл» и «ответ не дошёл».
     *
     * Только первое доказывает отсутствие выдачи. Признак — соединение так и
     * не установилось: у Guzzle это errno 6/7/35 (DNS, connection refused,
     * ошибка TLS) либо нулевое connect_time.
     */
    private function classifyTransportFailure(ConnectionException $e, int $latencyMs): SupplierResponse
    {
        $previous = $e->getPrevious();
        $context = $previous instanceof ConnectException ? $previous->getHandlerContext() : [];

        $errno = isset($context['errno']) && is_int($context['errno']) ? $context['errno'] : 0;
        $connectTime = isset($context['connect_time']) && is_numeric($context['connect_time'])
            ? (float) $context['connect_time']
            : null;

        $neverConnected = in_array($errno, [6, 7, 35], true) || $connectTime === 0.0;

        return new SupplierResponse(
            outcome: $neverConnected ? CallOutcome::NotIssuedCertain : CallOutcome::Unknown,
            errorKind: $neverConnected ? 'connect_failed:'.$errno : 'read_timeout:'.$errno,
            latencyMs: $latencyMs,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
