<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Delivery\Actions\DeliverOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(private readonly string $orderPublicId)
    {
        // Отдельная очередь: медленная выдача не имеет права морить голодом
        // применение платежей.
        $this->onQueue('delivery');
    }

    public function handle(DeliverOrder $action): void
    {
        $action->execute($this->orderPublicId);
    }
}
