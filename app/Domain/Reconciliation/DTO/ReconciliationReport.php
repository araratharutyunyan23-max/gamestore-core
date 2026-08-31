<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\DTO;

use App\Domain\Reconciliation\Enums\FindingSeverity;

/**
 * Отчёт сверки.
 *
 * Здоровье считается только по critical. Ожидание пополнения склада —
 * warning: заказ в out_of_stock это пауза, а не инцидент, и если считать
 * его нездоровьем, система будет красной ровно тогда, когда работает
 * штатно (критерий приёмки №6).
 */
final readonly class ReconciliationReport
{
    /**
     * @param  list<Anomaly>  $anomalies
     */
    public function __construct(
        public array $anomalies,
        public int $ledgerImbalanceMinor,
        public int $openPrepaymentMinor,
    ) {}

    public function isHealthy(): bool
    {
        return $this->criticalCount() === 0 && $this->ledgerImbalanceMinor === 0;
    }

    public function criticalCount(): int
    {
        return count(array_filter(
            $this->anomalies,
            static fn (Anomaly $a): bool => $a->severity() === FindingSeverity::Critical,
        ));
    }

    public function warningCount(): int
    {
        return count($this->anomalies) - $this->criticalCount();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $byKind = [];

        foreach ($this->anomalies as $anomaly) {
            $byKind[$anomaly->kind->value] = ($byKind[$anomaly->kind->value] ?? 0) + 1;
        }

        ksort($byKind);

        return [
            'healthy' => $this->isHealthy(),
            'summary' => [
                'critical' => $this->criticalCount(),
                'warning' => $this->warningCount(),
                // Суммарный дисбаланс журнала. Ноль здесь — необходимое условие
                // здоровья, но не достаточное: две ошибочные проводки в разные
                // стороны тоже дают ноль, поэтому есть ещё детектор по каждой
                // денежной транзакции отдельно.
                'ledger_imbalance_minor' => $this->ledgerImbalanceMinor,
                // Деньги за оплаченные, но не выданные заказы. Второй,
                // независимый способ увидеть ту же картину, что и детектор
                // «оплачен, но не выдан».
                'open_prepayment_minor' => $this->openPrepaymentMinor,
                'by_kind' => $byKind,
            ],
            'anomalies' => array_map(static fn (Anomaly $a): array => $a->toArray(), $this->anomalies),
        ];
    }
}
