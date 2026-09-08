<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Jobs\DeliverOrderJob;
use App\Support\StructuredLog;

/**
 * Очередь на выдачу: обслужить то, чему пришло время.
 *
 * Отдельный проход, а не подметальщик застрявших заказов. Разница в скорости
 * и в смысле: подметальщик спасает то, что сломалось, и потому ждёт пятнадцать
 * минут; очередь обслуживает то, что исправно ждёт своей квоты, и ждать
 * пятнадцать минут ей незачем.
 *
 * Очередь живёт в базе, а не в памяти воркера. ТЗ 3.1 требует, чтобы ничего
 * не терялось, а очередь в памяти исчезает вместе с процессом — и с ней
 * исчезают оплаченные заказы.
 */
final readonly class DrainDeliveryQueue
{
    private const BATCH = 200;

    public function __construct(private OrderItemRepository $items) {}

    /** @return int сколько заказов отправлено на выдачу */
    public function execute(): int
    {
        $rows = $this->items->queuedForDelivery(self::BATCH);

        foreach ($rows as $row) {
            DeliverOrderJob::dispatch($row->public_id);
        }

        if ($rows !== []) {
            StructuredLog::delivery('delivery_queue_drained', 'batch', reason: (string) count($rows));
        }

        return count($rows);
    }
}
