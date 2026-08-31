<?php

declare(strict_types=1);

return [
    // Событие, не применённое дольше этого срока, переставляется в очередь.
    // Порог не нулевой намеренно: только что принятое событие уже стоит
    // в очереди, и доводка не должна с ней соревноваться.
    'drain_after_seconds' => (int) env('PAYMENTS_DRAIN_AFTER_SECONDS', 30),
    'drain_batch_size' => (int) env('PAYMENTS_DRAIN_BATCH_SIZE', 200),
];
