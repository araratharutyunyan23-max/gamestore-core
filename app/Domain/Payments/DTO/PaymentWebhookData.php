<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Support\MoneyParser;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Тело вебхука, приведённое к типам.
 *
 * Разбор намеренно не бросает наружу: непонятное тело сохраняется как
 * malformed и получает 200. Ответ 4xx/5xx заставил бы платёжную систему
 * ретраить вечно то, что мы всё равно никогда не разберём.
 */
final readonly class PaymentWebhookData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $eventId,
        public string $orderPublicId,
        public PaymentStatus $status,
        public ?int $amountMinor,
        public ?string $currency,
        public ?Carbon $occurredAt,
        public array $payload,
        public bool $malformed,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $eventId = self::stringOrNull($payload['event_id'] ?? null);
        $orderId = self::stringOrNull($payload['order_id'] ?? null);
        $rawStatus = self::stringOrNull($payload['status'] ?? null);
        $status = $rawStatus === null ? null : PaymentStatus::tryFrom($rawStatus);

        $amountMinor = null;
        $amountBroken = false;

        if (array_key_exists('amount', $payload)) {
            try {
                $amountMinor = MoneyParser::majorToMinor($payload['amount']);
            } catch (Throwable) {
                $amountBroken = true;
            }
        }

        $malformed = $eventId === null || $orderId === null || $status === null || $amountBroken;

        return new self(
            eventId: $eventId ?? '',
            orderPublicId: $orderId ?? '',
            status: $status ?? PaymentStatus::Unknown,
            amountMinor: $amountMinor,
            currency: self::stringOrNull($payload['currency'] ?? null),
            occurredAt: self::timeOrNull($payload['created_at'] ?? null),
            payload: $payload,
            malformed: $malformed,
        );
    }

    /**
     * Отпечаток тела. Повтор того же event_id с ДРУГИМ содержимым — не честный
     * дубль, а инцидент, и потерять его молча нельзя.
     */
    public function fingerprint(): string
    {
        $canonical = $this->payload;
        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timeOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
