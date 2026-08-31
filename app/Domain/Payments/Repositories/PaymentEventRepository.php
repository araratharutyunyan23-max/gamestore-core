<?php

declare(strict_types=1);

namespace App\Domain\Payments\Repositories;

use App\Domain\Payments\DTO\PaymentWebhookData;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Models\PaymentEvent;
use Illuminate\Database\ConnectionInterface;

final readonly class PaymentEventRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Приём события: одна короткая вставка и всё.
     *
     * Конкуренция переносится с дорогой бизнес-логики на дешёвый конфликт по
     * уникальному индексу: 50 одновременных вебхуков дают одну строку и 49
     * безобидных no-op вместо 50 параллельных выдач.
     */
    public function insertIgnoringDuplicates(PaymentWebhookData $data): void
    {
        $this->db->table('payment_events')->insertOrIgnore([
            'event_id' => $data->eventId,
            'order_public_id' => $data->orderPublicId,
            'status' => $data->status->value,
            'amount_minor' => $data->amountMinor,
            'currency' => $data->currency,
            'occurred_at' => $data->occurredAt,
            'received_at' => now(),
            'process_state' => $data->malformed
                ? PaymentEventState::Malformed->value
                : PaymentEventState::Pending->value,
            'applied_at' => $data->malformed ? now() : null,
            'body_fingerprint' => $data->fingerprint(),
            'payload' => json_encode($data->payload, JSON_THROW_ON_ERROR),
        ]);
    }

    public function findByEventId(string $eventId): ?PaymentEvent
    {
        return PaymentEvent::query()->where('event_id', $eventId)->first();
    }

    /**
     * Решение о постановке задачи принимается ПО СОСТОЯНИЮ, а не по факту вставки.
     *
     * `if ($inserted) dispatch(...)` теряет платёж навсегда: падение между COMMIT
     * и постановкой задачи означает, что все последующие ретраи платёжной системы
     * попадут в ON CONFLICT DO NOTHING и задача не встанет уже никогда.
     */
    public function isUnapplied(string $eventId): bool
    {
        return PaymentEvent::query()
            ->where('event_id', $eventId)
            ->whereNull('applied_at')
            ->exists();
    }

    /**
     * Пометить событие обработанным — ТОЛЬКО если оно ещё не обработано.
     *
     * Условие `applied_at IS NULL` обязательно. Без него проигравший гонку
     * обработчик перетирает уже выставленное 'applied' своим 'stale', и тогда
     * рушится сразу два механизма: индекс payment_events_one_applied_paid_uq
     * перестаёт видеть применённое событие, а сверка объявляет выданный заказ
     * неоплаченным.
     */
    public function markProcessed(PaymentEvent $event, PaymentEventState $state): void
    {
        $this->db->table('payment_events')
            ->where('id', $event->id)
            ->whereNull('applied_at')
            ->update([
                'process_state' => $state->value,
                'applied_at' => now(),
                'attempts' => $this->db->raw('attempts + 1'),
            ]);
    }

    public function fingerprintMatches(string $eventId, string $fingerprint): bool
    {
        return PaymentEvent::query()
            ->where('event_id', $eventId)
            ->where('body_fingerprint', $fingerprint)
            ->exists();
    }
}
