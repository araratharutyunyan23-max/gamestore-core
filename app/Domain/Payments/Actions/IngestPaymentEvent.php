<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\DTO\PaymentWebhookData;
use App\Domain\Payments\Repositories\PaymentEventRepository;
use App\Jobs\ApplyPaymentEventJob;
use App\Support\StructuredLog;

/**
 * Приём вебхука. Всё, что здесь происходит, обязано занимать миллисекунды.
 *
 * Контракт требует быстрый 200, а 5xx означает повтор доставки. Если бы выдача
 * шла синхронно, таймаут поставщика превращался бы в 5xx, платёжная система
 * повторяла вебхук — и мы сами порождали бы ровно тот сценарий с 50
 * параллельными событиями, который потом обязаны пережить.
 */
final readonly class IngestPaymentEvent
{
    public function __construct(private PaymentEventRepository $events) {}

    public function execute(PaymentWebhookData $data): void
    {
        if ($data->malformed) {
            $this->events->insertIgnoringDuplicates($data);
            StructuredLog::webhook('webhook_malformed', $data->eventId, $data->orderPublicId);

            return;
        }

        $duplicate = $this->events->findByEventId($data->eventId) !== null;
        $sameBody = $duplicate && $this->events->fingerprintMatches($data->eventId, $data->fingerprint());

        $this->events->insertIgnoringDuplicates($data);

        if ($duplicate && ! $sameBody) {
            // Тот же event_id с другим содержимым — нарушение контракта на стороне
            // платёжной системы. Отвечаем 200, чтобы не ловить вечные ретраи,
            // но факт фиксируем: молча потерять платёж нельзя.
            StructuredLog::webhook('webhook_event_id_reuse', $data->eventId, $data->orderPublicId);
        }

        // Диспатч по СОСТОЯНИЮ, а не по факту вставки. Падение между COMMIT и
        // постановкой задачи иначе теряет платёж навсегда: все повторы уйдут
        // в ON CONFLICT DO NOTHING, и задача не встанет уже никогда.
        if ($this->events->isUnapplied($data->eventId)) {
            ApplyPaymentEventJob::dispatch($data->eventId)->afterCommit();
            StructuredLog::webhook('webhook_received', $data->eventId, $data->orderPublicId);

            return;
        }

        StructuredLog::webhook('webhook_deduped', $data->eventId, $data->orderPublicId);
    }
}
