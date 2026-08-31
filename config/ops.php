<?php

declare(strict_types=1);

return [
    // Эндпоинт сверки показывает состояние денег, поэтому закрыт токеном.
    'token' => (string) env('OPS_TOKEN', ''),

    // Порог свежести сверки для /health. Шедулер гоняет её раз в минуту,
    // порог с запасом: одна пропущенная минута — не авария, десять — авария.
    'reconciliation_max_age_seconds' => (int) env('OPS_RECONCILIATION_MAX_AGE', 600),
];
