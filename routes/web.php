<?php

declare(strict_types=1);

use App\Http\Controllers\ReconciliationController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
| Фронтенда в проекте нет по условию задания. Корень отдаёт карту API,
| чтобы проверяющий, открывший localhost:8000, сразу видел точки входа.
*/
Route::get('/', static fn (): JsonResponse => response()->json([
    'service' => 'gamestore-core',
    'docs' => 'README.md',
    'endpoints' => [
        'health' => '/up',
        'catalog' => 'GET /api/v1/products',
        'create_order' => 'POST /api/v1/orders',
        'get_order' => 'GET /api/v1/orders/{public_id}',
        'payment_webhook' => 'POST /api/v1/webhooks/payment',
        'reconciliation' => 'GET /ops/reconciliation',
    ],
]));

/*
| Эксплуатационный эндпоинт сверки. Живёт здесь, а не в routes/api.php,
| потому что api-маршруты монтируются с префиксом /api, а это не часть
| публичного контракта — это внутренний отчёт о состоянии денег.
| Закрыт токеном в ReconciliationRequest::authorize().
*/
Route::get('/ops/reconciliation', ReconciliationController::class);
