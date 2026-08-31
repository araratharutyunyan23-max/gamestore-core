<?php

declare(strict_types=1);

return [
    'lease_seconds' => (int) env('DELIVERY_LEASE_SECONDS', 120),
    'stuck_after_minutes' => (int) env('DELIVERY_STUCK_AFTER_MINUTES', 15),
];
