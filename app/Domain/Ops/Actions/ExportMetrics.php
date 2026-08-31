<?php

declare(strict_types=1);

namespace App\Domain\Ops\Actions;

use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ops\Repositories\OpsRepository;

/**
 * Плоский текстовый экспортер в формате Prometheus.
 *
 * Библиотека сюда не тянется намеренно: формат — это четыре строки на метрику,
 * а зависимость пришлось бы сопровождать. Значения берутся из уже посчитанных
 * состояний (статусы заказов, состояния событий, открытые находки), поэтому
 * опрос стоит несколько группировок, а не обход журнала.
 */
final readonly class ExportMetrics
{
    public function __construct(
        private OpsRepository $ops,
        private LedgerRepository $ledger,
    ) {}

    public function execute(): string
    {
        $lines = [];

        $this->gauge($lines, 'gamestore_orders', 'Заказы по статусам', 'status', $this->ops->orderCountsByStatus());
        $this->gauge($lines, 'gamestore_payment_events', 'События платежей по состоянию инбокса', 'state', $this->ops->paymentEventCountsByState());
        $this->gauge($lines, 'gamestore_delivery_attempts', 'Обращения к поставщикам по исходу', 'outcome', $this->ops->deliveryAttemptCountsByOutcome());
        $this->gauge($lines, 'gamestore_open_findings', 'Открытые находки сверки', 'severity', $this->ops->openFindingCountsBySeverity());

        // Две метрики, ради которых экспортер вообще нужен: расхождение
        // журнала обязано быть нулём всегда, а объём незакрытой предоплаты —
        // это деньги, за которые мы ещё не выдали товар.
        $this->single($lines, 'gamestore_ledger_imbalance_minor', 'Расхождение двойной записи в копейках', $this->ledger->totalImbalanceMinor());
        $this->single($lines, 'gamestore_open_prepayment_minor', 'Незакрытая предоплата в копейках', $this->ledger->openPrepaymentMinor());

        $age = $this->ops->lastReconciliationAgeSeconds();
        $this->single($lines, 'gamestore_reconciliation_age_seconds', 'Возраст последней сверки', $age ?? -1);

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, int>  $values
     */
    private function gauge(array &$lines, string $name, string $help, string $label, array $values): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";

        if ($values === []) {
            // Метрика без единой строки исчезает из выдачи, а исчезнувшая
            // метрика в мониторинге неотличима от сломавшегося экспортера.
            $lines[] = "{$name}{{$label}=\"none\"} 0";

            return;
        }

        foreach ($values as $bucket => $count) {
            $lines[] = sprintf('%s{%s="%s"} %d', $name, $label, $bucket, $count);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function single(array &$lines, string $name, string $help, int $value): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        $lines[] = "{$name} {$value}";
    }
}
