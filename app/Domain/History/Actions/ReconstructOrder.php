<?php

declare(strict_types=1);

namespace App\Domain\History\Actions;

use App\Domain\History\DTO\OrderSnapshot;
use App\Domain\History\Repositories\HistoryRepository;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Состояние заказа на любой прошедший момент (ТЗ 4.1).
 *
 * Собирается из append-only журналов переходов. Ни одно поле не читается из
 * текущей строки заказа — кроме того, что не меняется: суммы и момента
 * создания. Иначе «состояние на вчера» показывало бы сегодняшнюю правду
 * с прошлогодней датой.
 *
 * Отсутствие переходов у заказа означает не «нет данных», а начальный статус:
 * заказ создан и с тех пор никуда не переходил.
 */
final readonly class ReconstructOrder
{
    public function __construct(private HistoryRepository $history) {}

    public function execute(Order $order, CarbonImmutable $asOf): OrderSnapshot
    {
        if ($order->created_at->greaterThan($asOf)) {
            return new OrderSnapshot(
                orderPublicId: $order->public_id,
                asOf: $asOf,
                existed: false,
                status: null,
                itemStatuses: [],
                deliveredItems: 0,
                amountMinor: 0,
            );
        }

        $status = $this->history->orderStatusAsOf($order->id, $asOf) ?? OrderStatus::Created->value;
        $itemStatuses = $this->history->itemStatusesAsOf($order->id, $asOf);

        // Позиция без единого перехода к этому моменту была в исходном
        // состоянии. Пропустить её значило бы показать заказ, у которого
        // часть товаров просто отсутствует.
        foreach ($order->items as $item) {
            if (! array_key_exists($item->line_no, $itemStatuses)) {
                $itemStatuses[$item->line_no] = OrderItemStatus::Pending->value;
            }
        }

        ksort($itemStatuses);

        return new OrderSnapshot(
            orderPublicId: $order->public_id,
            asOf: $asOf,
            existed: true,
            status: $status,
            itemStatuses: $itemStatuses,
            deliveredItems: $this->history->deliveredCountAsOf($order->id, $asOf),
            amountMinor: $order->amount_minor,
        );
    }
}
