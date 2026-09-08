<?php

declare(strict_types=1);

namespace App\Domain\Delivery\RateLimit;

/**
 * Ответ квоты: пускать или подождать.
 *
 * Отказ несёт СРОК, а не просто «нет». Без срока вызывающий код обязан
 * выдумать паузу сам, и выдумает он её одинаковой для всех воркеров — то есть
 * соберёт их в стадо, которое ломится в поставщика одновременно.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterMs,
    ) {}

    public static function allowed(): self
    {
        return new self(true, 0);
    }

    public static function denied(int $retryAfterMs): self
    {
        return new self(false, max(1, $retryAfterMs));
    }
}
