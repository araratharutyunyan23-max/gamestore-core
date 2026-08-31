<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ops\Actions\ExportMetrics;
use App\Http\Requests\MetricsRequest;
use Illuminate\Http\Response;

final class MetricsController
{
    public function __construct(private readonly ExportMetrics $action) {}

    public function __invoke(MetricsRequest $request): Response
    {
        return response($this->action->execute(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
