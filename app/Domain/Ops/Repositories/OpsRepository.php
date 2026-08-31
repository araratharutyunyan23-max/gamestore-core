<?php

declare(strict_types=1);

namespace App\Domain\Ops\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Чтение уже посчитанных состояний для /health и /metrics.
 *
 * Все методы — одна агрегирующая выборка каждый. Это принципиально: экспорт
 * метрик опрашивают раз в 15 секунд, и запрос, который считает по строкам,
 * со временем начнёт стоить дороже самой торговли.
 */
final readonly class OpsRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function recordReconciliationRun(bool $full, int $anomalies, int $critical): void
    {
        $this->db->table('reconciliation_runs')->insert([
            'full' => $full,
            'anomalies_count' => $anomalies,
            'critical_count' => $critical,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function lastReconciliationAgeSeconds(): ?int
    {
        $row = $this->db->table('reconciliation_runs')
            ->select('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        if ($row === null || ! property_exists($row, 'finished_at') || ! is_string($row->finished_at)) {
            return null;
        }

        return max(0, (int) Carbon::now()->diffInSeconds(Carbon::parse($row->finished_at), absolute: true));
    }

    /**
     * @return array<string, int>
     */
    public function orderCountsByStatus(): array
    {
        return $this->counts('orders', 'status');
    }

    /**
     * @return array<string, int>
     */
    public function paymentEventCountsByState(): array
    {
        return $this->counts('payment_events', 'process_state');
    }

    /**
     * @return array<string, int>
     */
    public function openFindingCountsBySeverity(): array
    {
        return $this->counts('reconciliation_findings', 'severity', openOnly: true);
    }

    /**
     * @return array<string, int>
     */
    public function deliveryAttemptCountsByOutcome(): array
    {
        return $this->counts('delivery_attempts', 'outcome');
    }

    public function databaseIsReachable(): bool
    {
        try {
            $this->db->select('select 1');

            return true;
        } catch (\Throwable) {
            // Причина не важна: снаружи есть ровно два состояния — база
            // отвечает или нет. Подробности лежат в логе исключения.
            return false;
        }
    }

    /**
     * Одна группировка вместо выборки строк.
     *
     * @return array<string, int>
     */
    private function counts(string $table, string $column, bool $openOnly = false): array
    {
        $query = $this->db->table($table)
            ->selectRaw("{$column} as bucket, count(*) as total")
            ->groupBy($column);

        if ($openOnly) {
            $query->whereNull('resolved_at');
        }

        $counts = [];

        foreach ($query->get()->all() as $row) {
            $bucket = property_exists($row, 'bucket') && is_string($row->bucket) ? $row->bucket : 'unknown';
            $total = property_exists($row, 'total') ? $row->total : 0;

            $counts[$bucket] = is_numeric($total) ? (int) $total : 0;
        }

        return $counts;
    }
}
