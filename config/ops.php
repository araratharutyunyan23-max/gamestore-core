<?php

declare(strict_types=1);

return [
    // Эндпоинт сверки показывает состояние денег, поэтому закрыт токеном.
    'token' => (string) env('OPS_TOKEN', ''),
];
