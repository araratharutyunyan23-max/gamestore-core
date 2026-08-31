<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryAttemptRepository;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Jobs\DeliverOrderJob;
use App\Support\StructuredLog;

/**
 * Фоновое доведение обращений, застрявших в неизвестности.
 *
 * Без него заказ, по которому поставщик промолчал, висит вечно: попытка
 * открыта, а открытая попытка по построению запрещает начать новую. Здесь
 * судьба выясняется тем же способом — probe и печать по ТОМУ ЖЕ request_id,
 * — и новый идентификатор не создаётся никогда.
 */
final readonly class ResolveStuckAttempts
{
    private const BATCH = 50;

    public function __construct(
        private DeliveryAttemptRepository $attempts,
        private OrderRepository $orders,
        private ResolveSupplierAttempt $resolver,
    ) {}

    /** @return int сколько обращений удалось разрешить */
    public function execute(): int
    {
        $resolved = 0;

        foreach ($this->attempts->unresolvedDue(self::BATCH) as $row) {
            $order = $this->orders->lockById($row->order_id);

            if ($order === null) {
                continue;
            }

            $supplier = SupplierName::from($row->supplier);
            $requestId = RequestId::for($order->public_id, $supplier, $order->delivery_epoch);

            $result = $this->resolver->resolve($order, $supplier, $requestId, $row->id);

            if ($result['outcome'] === DeliveryOutcome::AwaitingResolution) {
                continue;
            }

            $resolved++;

            // Судьба выяснена — доводить заказ будет обычный путь выдачи:
            // он идемпотентен, и код, уже лежащий в журнале обязательств,
            // будет использован, а не куплен заново.
            DeliverOrderJob::dispatch($order->public_id);

            StructuredLog::delivery('supplier_unknown_resolved', $order->public_id, reason: $result['outcome']->value);
        }

        return $resolved;
    }
}
