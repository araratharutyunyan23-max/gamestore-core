<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Repositories;

use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use Illuminate\Database\ConnectionInterface;

/**
 * Журнал обращений к поставщику.
 *
 * Строка пишется и КОММИТИТСЯ ДО сетевого вызова. Это не бухгалтерия, а
 * crash-safety: если процесс умрёт ровно между отправкой запроса и получением
 * ответа, единственным следом останется эта строка. По ней восстановление
 * узнает, какой request_id уже был отправлен, и повторит именно его — а не
 * создаст новый и не купит второй код.
 */
final readonly class DeliveryAttemptRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Записать намерение обратиться к поставщику.
     *
     * Нарушение delivery_attempts_one_open_uq здесь означает «по заказу уже
     * есть неразрешённая попытка» — и это правильный отказ, а не сбой:
     * открывать вторую, пока судьба первой неизвестна, нельзя.
     */
    public function begin(int $orderId, SupplierName $supplier, RequestId $requestId, int $epoch, string $traceId): int
    {
        /** @var int $id */
        $id = $this->db->table('delivery_attempts')->insertGetId([
            'order_id' => $orderId,
            'supplier' => $supplier->value,
            'request_id' => $requestId->value,
            'epoch' => $epoch,
            'outcome' => AttemptOutcome::InFlight->value,
            'started_at' => now(),
            'trace_id' => $traceId,
        ]);

        return $id;
    }

    public function finish(
        int $attemptId,
        AttemptOutcome $outcome,
        ?int $httpStatus = null,
        ?string $errorKind = null,
        ?int $latencyMs = null,
        ?string $storeEpoch = null,
    ): void {
        $this->db->table('delivery_attempts')->where('id', $attemptId)->update([
            'outcome' => $outcome->value,
            // definitive помечает исход, ДОКАЗЫВАЮЩИЙ отсутствие выдачи.
            // Только он разрешает открыть попытку к другому поставщику.
            'definitive' => $outcome === AttemptOutcome::Failed || $outcome === AttemptOutcome::Sealed,
            'http_status' => $httpStatus,
            'error_kind' => $errorKind,
            'latency_ms' => $latencyMs,
            'store_epoch' => $storeEpoch,
            'finished_at' => now(),
        ]);
    }

    public function scheduleProbe(int $attemptId, int $delaySeconds): void
    {
        $this->db->table('delivery_attempts')->where('id', $attemptId)->update([
            'probe_count' => $this->db->raw('probe_count + 1'),
            'next_probe_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    /**
     * Неразрешённые попытки — worklist фонового выяснения судьбы.
     *
     * @return list<object{id: int, order_id: int, supplier: string, request_id: string, probe_count: int}>
     */
    public function unresolvedDue(int $limit): array
    {
        /** @var list<object{id: int, order_id: int, supplier: string, request_id: string, probe_count: int}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT id, order_id, supplier, request_id, probe_count
              FROM delivery_attempts
             WHERE outcome IN ('in_flight', 'timeout', 'unknown')
               AND (next_probe_at IS NULL OR next_probe_at <= now())
             ORDER BY started_at
             LIMIT ?
        SQL, [$limit]);

        return $rows;
    }

    public function openAttemptFor(int $orderId): ?object
    {
        /** @var list<object{id: int, supplier: string, request_id: string, outcome: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT id, supplier, request_id, outcome
              FROM delivery_attempts
             WHERE order_id = ? AND outcome IN ('in_flight', 'timeout', 'unknown')
             LIMIT 1
        SQL, [$orderId]);

        return $rows[0] ?? null;
    }
}
