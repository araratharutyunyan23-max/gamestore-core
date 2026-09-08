<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\DeliveryTarget;
use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryAttemptRepository;
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
        private ResolveSupplierAttempt $resolver,
    ) {}

    /** @return int сколько обращений удалось разрешить */
    public function execute(): int
    {
        $resolved = 0;

        foreach ($this->attempts->unresolvedDue(self::BATCH) as $row) {
            $supplier = SupplierName::from($row->supplier);

            // Идентификатор берётся ИЗ ПОПЫТКИ, а не собирается заново из
            // текущей эпохи. Пересборка была скрытой ловушкой: сдвинься эпоха
            // после начала попытки — и мы спрашивали бы поставщика о судьбе
            // чужого запроса, то есть решали бы, что кода нет, когда он есть.
            $requestId = RequestId::restore($row->request_id, $row->epoch);

            $target = new DeliveryTarget(
                itemId: $row->order_item_id,
                orderId: $row->order_id,
                productId: $row->product_id,
                orderPublicId: $row->public_id,
                lineNo: $row->line_no,
                sku: $row->sku,
                deliveryEpoch: $row->epoch,
            );

            $result = $this->resolver->resolve($target, $supplier, $requestId, $row->id);

            if ($result['outcome'] === DeliveryOutcome::AwaitingResolution) {
                continue;
            }

            $resolved++;

            // Судьба выяснена — доводить заказ будет обычный путь выдачи:
            // он идемпотентен, и код, уже лежащий в журнале обязательств,
            // будет использован, а не куплен заново.
            DeliverOrderJob::dispatch($target->orderPublicId);

            StructuredLog::delivery('supplier_unknown_resolved', $target->reference(), reason: $result['outcome']->value);
        }

        return $resolved;
    }
}
