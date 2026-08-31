<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\DTO;

use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Enums\FindingSeverity;

final readonly class Anomaly
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public FindingKind $kind,
        public string $subject,
        public array $details = [],
    ) {}

    public function severity(): FindingSeverity
    {
        return $this->kind->severity();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->severity()->value,
            'subject' => $this->subject,
            'details' => $this->details,
        ];
    }
}
