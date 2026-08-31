<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ReconciliationController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
| Фронтенда в проекте нет по условию задания. Корень отдаёт карту API,
| чтобы проверяющий, открывший localhost:8000, сразу видел точки входа.
*/
Route::get('/', static fn (): JsonResponse => response()->json([
    'service' => 'gamestore-core',
    'docs' => 'README.md',
    'endpoints' => [
        'health' => 'GET /health',
        'metrics' => 'GET /metrics',
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

/*
| Живость и метрики (CLAUDE.md §7). /health открыт — его опрашивает
| балансировщик, и секретов в ответе нет. /metrics закрыт тем же
| эксплуатационным токеном: по счётчикам заказов читается оборот.
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/metrics', MetricsController::class)->name('metrics');

/*
| Живая документация API. Спецификация отдаётся тем же приложением, а не лежит
| отдельно: так не может случиться, что развёрнута одна версия, а описана другая.
*/
Route::get('/docs', static fn (): View => view('docs'))->name('docs');

Route::get('/docs/openapi.yaml', static function (): Response {
    return response()
        ->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml; charset=utf-8']);
})->name('docs.spec');
