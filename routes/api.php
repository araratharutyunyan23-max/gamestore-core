<?php

declare(strict_types=1);

use App\Http\Controllers\CreateOrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ShowOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(static function (): void {
    Route::post('/orders', CreateOrderController::class);
    Route::get('/orders/{publicId}', ShowOrderController::class);

    // Троттлинга здесь нет намеренно: 429 для платёжной системы означает
    // неудачную доставку и вечные повторы одного и того же события.
    Route::post('/webhooks/payment', PaymentWebhookController::class);
});
