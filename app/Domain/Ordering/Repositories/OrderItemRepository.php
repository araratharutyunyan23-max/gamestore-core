<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repositories;

use App\Domain\Ordering\DTO\ItemLease;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Models\OrderItem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Весь доступ к позициям заказа.
 *
 * Аренда живёт здесь, а не на заказе, по прямой причине: две позиции одного
 * заказа идут к разным поставщикам и обязаны выдаваться независимо. Одна
 * аренда на заказ означала бы, что один залипший поставщик держит весь заказ,
 * включая позиции, которые давно готовы к выдаче.
 */
final readonly class OrderItemRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Сколько позиций заказа в каком состоянии.
     *
     * Одной группировкой, а не выборкой строк: пересчёт статуса заказа идёт
     * после каждой выдачи, и читать ради него все позиции целиком означало бы
     * платить за них по разу на позицию.
     *
     * @return array<string, int>
     */
    public function statusCountsForOrder(int $orderId): array
    {
        $rows = $this->db->table('order_items')
            ->selectRaw('status, count(*) as total')
            ->where('order_id', $orderId)
            ->groupBy('status')
            ->get()
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            $status = property_exists($row, 'status') && is_string($row->status) ? $row->status : null;
            $total = property_exists($row, 'total') ? $row->total : 0;

            if ($status !== null && is_numeric($total)) {
                $counts[$status] = (int) $total;
            }
        }

        return $counts;
    }

    /**
     * Позиции заказа, которые ещё предстоит выдать.
     *
     * @return list<OrderItem>
     */
    public function awaitingDeliveryForOrder(int $orderId): array
    {
        $items = OrderItem::query()
            ->with('product')
            ->where('order_id', $orderId)
            ->whereIn('status', array_map(
                static fn (OrderItemStatus $status): string => $status->value,
                OrderItemStatus::awaitingDelivery(),
            ))
            ->orderBy('line_no')
            ->get()
            ->all();

        return array_values($items);
    }

    /**
     * Перечитать позицию ВНУТРИ транзакции под блокировкой строки.
     *
     * Объект, прочитанный до начала транзакции, к моменту принятия решения уже
     * может не соответствовать базе. `for no key update`, а не `for update`:
     * блокировка не должна мешать вставке строк, ссылающихся на эту позицию.
     */
    public function lockById(int $id): ?OrderItem
    {
        return OrderItem::query()
            ->with('product')
            ->where('id', $id)
            ->lock('for no key update')
            ->first();
    }

    /**
     * Захватить аренду на выдачу позиции.
     *
     * Условный UPDATE — это и есть compare-and-set: второй конкурент
     * блокируется на строке, после коммита первого перепроверяет условие по
     * новой версии и получает ноль строк. «Я выиграл» означает ровно одно —
     * затронута одна строка.
     *
     * Протухшая аренда перехватывается по времени: воркер, упавший во время
     * выдачи, не блокирует позицию навсегда.
     */
    public function acquireLease(int $itemId, string $token, int $seconds, string $owner): ?ItemLease
    {
        $affected = $this->db->table('order_items')
            ->where('id', $itemId)
            ->whereIn('status', array_map(
                static fn (OrderItemStatus $status): string => $status->value,
                OrderItemStatus::awaitingDelivery(),
            ))
            ->where(function (Builder $query): void {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
            })
            ->update([
                'lease_token' => $token,
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($seconds),
                'updated_at' => now(),
            ]);

        return $affected === 1 ? new ItemLease($itemId, $token) : null;
    }

    /**
     * Снять аренду. Условие по токену обязательно: аренда могла протухнуть
     * и уйти другому воркеру, и снимать её тогда уже не наше дело.
     */
    public function releaseLease(ItemLease $lease): void
    {
        $this->db->table('order_items')
            ->where('id', $lease->itemId)
            ->where('lease_token', $lease->token)
            ->update([
                'lease_token' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Поднять эпоху выдачи позиции.
     *
     * В БАЗЕ, а не в памяти. Эпоха входит в request_id, и если она живёт только
     * в переменной, то повтор после восстановимого отказа уходит с тем же
     * идентификатором и упирается в delivery_attempts_request_uq — позицию
     * после этого не довести никогда.
     *
     * Растёт ТОЛЬКО после доказанного «не выдано». После таймаута — никогда:
     * новый идентификатор означал бы для поставщика новый запрос и второй код.
     */
    public function bumpDeliveryEpoch(int $itemId): void
    {
        $this->db->table('order_items')->where('id', $itemId)->update([
            'delivery_epoch' => $this->db->raw('delivery_epoch + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Позиции, застрявшие в ожидании выдачи.
     *
     * Выборка идёт по частичному индексу order_items_worklist_idx, который
     * покрывает только невыполненные позиции и потому не растёт вместе с
     * историей. Позиции под живой арендой исключаются: их прямо сейчас выдают.
     *
     * @return list<object{public_id: string, line_no: int, status: string}>
     */
    public function stuckAwaitingDelivery(int $olderThanMinutes, int $limit): array
    {
        /** @var list<object{public_id: string, line_no: int, status: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT o.public_id, i.line_no, i.status
              FROM order_items i
              JOIN orders o ON o.id = i.order_id
             WHERE i.status IN ('pending', 'delivering', 'out_of_stock', 'delivery_failed')
               AND i.status_changed_at < now() - make_interval(mins => ?)
               AND (i.lease_expires_at IS NULL OR i.lease_expires_at <= now())
             ORDER BY i.priority DESC, i.next_action_at
             LIMIT ?
        SQL, [$olderThanMinutes, $limit]);

        return $rows;
    }
}
