<?php

declare(strict_types=1);

use App\Domain\Catalog\Exceptions\ProductNotPurchasable;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Доменные исключения превращаются в HTTP-коды ЗДЕСЬ, а не в контроллерах:
        // иначе каждый контроллер обрастает своим try/catch, а формат ошибки
        // расходится от маршрута к маршруту.
        $exceptions->render(static function (ProductNotPurchasable $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => 'sku_not_purchasable',
                'message' => $e->getMessage(),
            ], 422);
        });

        // Нелегальный переход статуса — это дефект кода, а не ситуация клиента.
        // Отдаём 500 и оставляем в логах, чтобы такое не проходило незамеченным.
        $exceptions->render(static function (IllegalTransition $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => 'illegal_transition',
                'message' => $e->getMessage(),
            ], 500);
        });
    })->create();
