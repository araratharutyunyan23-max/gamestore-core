<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\Actions\FailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    /**
     * Без этого метода заказ навсегда зависает в delivering: попытки
     * кончились, задачи больше нет, а статус остался промежуточным, и ни
     * один восстановительный путь такой заказ не увидит.
     *
     * delivery_failed — восстановимое состояние, из него выдача продолжается
     * после ручного или фонового ретрая.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailDelivery::class)->execute($this->orderPublicId, $exception);
    }
}
