<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reconciliation\Actions\RunReconciliation;
use Illuminate\Console\Command;

final class ReconcileCommand extends Command
{
    protected $signature = 'shop:reconcile {--full : Полные проверки по всей истории} {--fail-on-anomaly}';

    protected $description = 'Сверка: оплачен но не выдан, выдан но не оплачен, дрейф остатка, дисбаланс журнала';

    public function handle(RunReconciliation $action): int
    {
        $report = $action->execute($this->option('full') === true);

        $this->components->twoColumnDetail('Критичных аномалий', (string) $report->criticalCount());
        $this->components->twoColumnDetail('Предупреждений', (string) $report->warningCount());
        $this->components->twoColumnDetail('Дисбаланс журнала', $report->ledgerImbalanceMinor.' коп.');
        $this->components->twoColumnDetail('Оплачено, но не выдано', $report->openPrepaymentMinor.' коп.');

        foreach ($report->anomalies as $anomaly) {
            $this->line(sprintf(
                '  [%s] %s — %s',
                $anomaly->severity()->value,
                $anomaly->kind->value,
                $anomaly->subject,
            ));
        }

        if ($report->isHealthy()) {
            $this->components->info('Расхождений нет.');
        } else {
            $this->components->warn('Есть критичные расхождения.');
        }

        // Ненулевой код возврата только по явному флагу: ночной прогон должен
        // уметь и просто отчитаться, не роняя пайплайн.
        return $this->option('fail-on-anomaly') === true && ! $report->isHealthy()
            ? self::FAILURE
            : self::SUCCESS;
    }
}
