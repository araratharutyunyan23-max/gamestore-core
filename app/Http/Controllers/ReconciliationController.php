<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Http\Requests\ReconciliationRequest;
use Illuminate\Http\JsonResponse;

/**
 * Отчёт сверки. Контроллер только зовёт сервис и отдаёт результат —
 * решение о HTTP-коде принимает отчёт, а не контроллер.
 */
final class ReconciliationController
{
    public function __construct(private readonly RunReconciliation $action) {}

    public function __invoke(ReconciliationRequest $request): JsonResponse
    {
        $report = $this->action->execute($request->full());

        // 409 при нездоровье: так эндпоинт можно повесить прямо в мониторинг
        // без разбора тела ответа.
        return response()->json($report->toArray(), $report->isHealthy() ? 200 : 409);
    }
}
