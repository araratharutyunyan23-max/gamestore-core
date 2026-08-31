<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Support\StructuredLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Применение платёжного события вне HTTP-запроса.
 *
 * Задача — только оркестратор: получает идентификатор, зовёт сервис, решает,
 * что делать с исходом. Бизнес-правил внутри нет.
 */
final class ApplyPaymentEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(private readonly string $eventId)
    {
        $this->onQueue('payments');
    }

    public function handle(ApplyPaymentEvent $action, DispatchDelivery $delivery): void
    {
        $state = $action->execute($this->eventId);

        if ($state === PaymentEventState::Applied) {
            // Диспатч выдачи — только после того, как платёж зафиксирован.
            $delivery->forEventOrder($this->eventId);
        }
    }

    /**
     * Событие остаётся с applied_at IS NULL — и это осознанно.
     *
     * Инбокс, а не статус заказа, является источником истины для
     * восстановления: неприменённое событие видно частичному индексу
     * payment_events_unapplied_idx, и фоновая доводка подберёт его позже.
     * Пометить событие обработанным здесь означало бы навсегда потерять платёж.
     */
    public function failed(?Throwable $exception): void
    {
        StructuredLog::webhook('payment_apply_failed', $this->eventId, $exception?->getMessage() ?? '');
    }
}
