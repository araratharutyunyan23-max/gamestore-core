<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\Actions\IngestPaymentEvent;
use App\Http\Requests\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Приём вебхука оплаты. Всё, что делает контроллер, — отдаёт тело сервису
 * и отвечает 200. Ни одной бизнес-проверки: 5xx здесь означает бесконечные
 * повторы со стороны платёжной системы.
 */
final class PaymentWebhookController
{
    public function __construct(private readonly IngestPaymentEvent $action) {}

    public function __invoke(PaymentWebhookRequest $request): JsonResponse
    {
        $this->action->execute($request->toData());

        return response()->json(['status' => 'accepted']);
    }
}
