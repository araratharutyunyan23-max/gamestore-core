<?php

declare(strict_types=1);

namespace App\Domain\History\DTO;

use Carbon\CarbonImmutable;

/**
 * Состояние заказа на прошедший момент.
 *
 * Собрано из журналов, а не из текущих строк: ответ о прошлом не имеет права
 * зависеть от того, что случилось после интересующего момента.
 */
final readonly class OrderSnapshot
{
    /**
     * @param  array<int, string>  $itemStatuses  номер строки => статус
     */
    public function __construct(
        public string $orderPublicId,
        public CarbonImmutable $asOf,
        public bool $existed,
        public ?string $status,
        public array $itemStatuses,
        public int $deliveredItems,
        public int $amountMinor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (! $this->existed) {
            // Заказа на тот момент не было. Это ответ, а не ошибка: вопрос
            // «в каком состоянии был заказ вчера» у заказа, созданного
            // сегодня, имеет ровно такой ответ.
            return [
                'id' => $this->orderPublicId,
                'as_of' => $this->asOf->toIso8601String(),
                'existed' => false,
            ];
        }

        $items = [];

        foreach ($this->itemStatuses as $lineNo => $status) {
            $items[] = ['line_no' => $lineNo, 'status' => $status];
        }

        return [
            'id' => $this->orderPublicId,
            'as_of' => $this->asOf->toIso8601String(),
            'existed' => true,
            'status' => $this->status,
            'amount_minor' => $this->amountMinor,
            'delivered_items' => $this->deliveredItems,
            'items' => $items,
        ];
    }
}
