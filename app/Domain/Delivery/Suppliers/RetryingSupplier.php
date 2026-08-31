<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\DTO\IssueRequest;
use App\Domain\Delivery\DTO\SupplierResponse;
use App\Domain\Delivery\Enums\CallOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Support\Cfg;
use Illuminate\Support\Sleep;

/**
 * Повторы с экспоненциальным бэкоффом и джиттером.
 *
 * Главное здесь — то, чего декоратор НЕ делает: он не меняет request_id между
 * попытками. Именно поэтому повтор после таймаута безопасен — поставщик по
 * контракту вернёт тот же код, а не выдаст второй.
 *
 * Повторяется только неизвестность. Доказанный отказ повторять бессмысленно,
 * а успех — тем более.
 *
 * Джиттер обязателен: без него все воркеры, столкнувшиеся с одним сбоем,
 * возвращаются одновременно и добивают уже нездорового поставщика.
 */
final readonly class RetryingSupplier implements SupplierGateway
{
    public function __construct(private SupplierGateway $inner) {}

    public function name(): SupplierName
    {
        return $this->inner->name();
    }

    public function issue(IssueRequest $request): SupplierResponse
    {
        $attempts = Cfg::supplierRetries() + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // ТОТ ЖЕ $request на каждой попытке. Новый request_id здесь
            // означал бы второй код при первом же таймауте.
            $last = $this->inner->issue($request);

            if ($last->outcome !== CallOutcome::Unknown) {
                return $last;
            }

            if ($attempt < $attempts) {
                Sleep::usleep($this->backoffMicroseconds($attempt));
            }
        }

        return $last ?? new SupplierResponse(CallOutcome::Unknown, errorKind: 'no_attempts');
    }

    public function probe(string $requestId): SupplierResponse
    {
        // Probe не повторяем: его повтором управляет фоновое разрешение
        // с собственным, гораздо более длинным расписанием.
        return $this->inner->probe($requestId);
    }

    public function seal(string $requestId): SupplierResponse
    {
        return $this->inner->seal($requestId);
    }

    private function backoffMicroseconds(int $attempt): int
    {
        $base = Cfg::supplierRetryBaseMs() * (2 ** ($attempt - 1));
        $jitter = random_int(0, $base);

        return ($base + $jitter) * 1000;
    }
}
