<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ops\Actions\CheckHealth;
use Illuminate\Http\JsonResponse;

/**
 * Живость сервиса. Без токена: проверять доступность обязан кто угодно,
 * включая балансировщик, а секретов в ответе нет — только «отвечает/нет».
 */
final class HealthController
{
    public function __construct(private readonly CheckHealth $action) {}

    public function __invoke(): JsonResponse
    {
        $report = $this->action->execute();

        // 503 при нездоровье: мониторинг обязан видеть проблему по коду
        // ответа, не разбирая тело.
        return response()->json($report->toArray(), $report->isHealthy() ? 200 : 503);
    }
}
