<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

/**
 * Запрос на выдачу по контракту задания.
 *
 * request_id ДЕТЕРМИНИРОВАН и строится из (заказ, поставщик, эпоха).
 * Все сетевые повторы внутри эпохи идут с ТЕМ ЖЕ идентификатором — только так
 * повтор после таймаута возвращает тот же код, а не создаёт второй.
 */
final readonly class IssueRequest
{
    public function __construct(
        public string $requestId,
        public string $sku,
        public string $orderPublicId,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'request_id' => $this->requestId,
            'sku' => $this->sku,
            'order_id' => $this->orderPublicId,
        ];
    }
}
