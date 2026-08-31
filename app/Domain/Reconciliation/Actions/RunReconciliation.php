<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ops\Repositories\OpsRepository;
use App\Domain\Reconciliation\DTO\Anomaly;
use App\Domain\Reconciliation\DTO\ReconciliationReport;
use App\Domain\Reconciliation\Enums\FindingSeverity;
use App\Domain\Reconciliation\Repositories\AnomalyQueryRepository;
use App\Domain\Reconciliation\Repositories\ReconciliationFindingRepository;
use App\Support\Cfg;
use App\Support\StructuredLog;

/**
 * Сверка: два вопроса задания — «оплачен, но не выдан» и «выдан, но не оплачен» —
 * плюс всё остальное, что может разъехаться незаметно.
 *
 * Найденное не только возвращается, но и записывается в reconciliation_findings:
 * отчёт читает человек, а работу подметальщика надо брать откуда-то ещё.
 */
final readonly class RunReconciliation
{
    public function __construct(
        private AnomalyQueryRepository $anomalies,
        private ReconciliationFindingRepository $findings,
        private LedgerRepository $ledger,
        private OpsRepository $ops,
    ) {}

    /**
     * @param  bool  $full  полные проверки, которые незачем гонять ежеминутно
     */
    public function execute(bool $full = false): ReconciliationReport
    {
        $stuckMinutes = Cfg::stuckAfterMinutes();

        /** @var list<Anomaly> $anomalies */
        $anomalies = [
            ...$this->anomalies->paidNotDelivered($stuckMinutes),
            ...$this->anomalies->deliveredNotPaid(),
            ...$this->anomalies->unappliedPayments(Cfg::drainAfterSeconds()),
            ...$this->anomalies->unresolvedSupplierAttempts($stuckMinutes),
            ...$this->anomalies->amountMismatches(),
        ];

        if ($full) {
            // Эти три проходят по всей истории и стоят дорого. Ежеминутно они
            // не нужны: расхождение такого рода не появляется за минуту.
            $anomalies = [
                ...$anomalies,
                ...$this->anomalies->unbalancedLedger(),
                ...$this->anomalies->stockDrift(),
                ...$this->anomalies->duplicateCodes(),
            ];
        }

        foreach ($anomalies as $anomaly) {
            $this->findings->record($anomaly->kind, null, $anomaly->subject, $anomaly->details);
            StructuredLog::finding($anomaly->kind->value, $anomaly->severity()->value, $anomaly->subject);
        }

        // Прогон фиксируется всегда, в том числе пустой: именно пустые
        // прогоны доказывают, что сверка жива, а данные здоровы.
        $this->ops->recordReconciliationRun(
            $full,
            count($anomalies),
            count(array_filter($anomalies, static fn (Anomaly $a): bool => $a->severity() === FindingSeverity::Critical)),
        );

        return new ReconciliationReport(
            anomalies: $anomalies,
            ledgerImbalanceMinor: $this->ledger->totalImbalanceMinor(),
            openPrepaymentMinor: $this->ledger->openPrepaymentMinor(),
        );
    }
}
