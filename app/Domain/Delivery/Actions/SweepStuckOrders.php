<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Ordering\Repositories\OrderRepository;
use App\Jobs\DeliverOrderJob;
use App\Support\Cfg;
use App\Support\StructuredLog;

/**
 * Безопасная доводка зависших заказов.
 *
 * «Безопасная» здесь означает конкретное: подметальщик НЕ делает ничего
 * особенного. Он лишь ставит обычную задачу выдачи — ту же самую, что
 * ставится после оплаты. Вся защита от задвоения уже встроена в неё:
 * аренда, детерминированный request_id, уникальные индексы.
 *
 * Отдельный «путь восстановления» со своей логикой — классический источник
 * второй выдачи: он расходится с основным ровно в тех переходах, которые
 * никто не догадался проверить.
 */
final readonly class SweepStuckOrders
{
    private const BATCH = 100;

    public function __construct(private OrderRepository $orders) {}

    /** @return int сколько заказов отправлено на повторную выдачу */
    public function execute(): int
    {
        $orders = $this->orders->stuckAwaitingDelivery(Cfg::stuckAfterMinutes(), self::BATCH);

        foreach ($orders as $order) {
            DeliverOrderJob::dispatch($order->public_id);
            StructuredLog::delivery('order_recovery_scheduled', $order->public_id, reason: $order->status);
        }

        return count($orders);
    }
}
