<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Repositories;

use App\Domain\Reconciliation\Enums\FindingKind;
use Illuminate\Database\ConnectionInterface;

/**
 * Находки сверки.
 *
 * Запись идёт через insertOrIgnore: повторный проход не должен размножать одну
 * и ту же аномалию, и защищает от этого частичный уникальный индекс
 * reconciliation_findings_open_uq по (вид, субъект) среди нерешённых.
 */
final readonly class ReconciliationFindingRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(FindingKind $kind, ?int $orderId, string $subjectRef, array $details = []): void
    {
        $this->db->table('reconciliation_findings')->insertOrIgnore([
            'kind' => $kind->value,
            'severity' => $kind->severity()->value,
            'order_id' => $orderId,
            'subject_ref' => $subjectRef,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'detected_at' => now(),
        ]);
    }
}
