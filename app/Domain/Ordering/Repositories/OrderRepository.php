<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repositories;

use App\Domain\Ordering\DTO\DeliveryLease;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Весь доступ к заказам.
 *
 * Чтение для API всегда идёт с eager loading: витрина заказа показывает товар
 * и выдачу, и ленивая загрузка превратила бы список в N+1. В local/testing
 * такая загрузка вообще бросает исключение (CLAUDE.md §4).
 */
final readonly class OrderRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findByPublicId(string $publicId): ?Order
    {
        return Order::query()
            ->with(['product', 'delivery', 'paymentState'])
            ->where('public_id', $publicId)
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        return Order::query()
            ->with(['product', 'delivery', 'paymentState'])
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Перечитать заказ ВНУТРИ транзакции под блокировкой строки.
     *
     * Объект, прочитанный до начала транзакции, к моменту принятия решения уже
     * может не соответствовать базе: между чтением и транзакцией успевает
     * пройти поздний failed, чужая выдача или отмена. Решение, принятое по
     * устаревшему статусу, выдаёт товар по отменённому заказу.
     *
     * FOR NO KEY UPDATE, а не FOR UPDATE: вставка дочерних строк (выдача,
     * переход статуса, проводка) берёт на родительской строке FOR KEY SHARE,
     * которая конфликтует с FOR UPDATE, но не с этой блокировкой.
     */
    public function lockById(int $id): ?Order
    {
        return Order::query()
            ->with(['product', 'delivery', 'paymentState'])
            ->where('id', $id)
            ->lock('for no key update')
            ->first();
    }

    /**
     * Захватить аренду на выдачу.
     *
     * Условный UPDATE — это и есть compare-and-set: второй конкурент
     * блокируется на строке, после коммита первого перепроверяет условие по
     * новой версии и получает ноль строк. «Я выиграл» означает ровно одно —
     * затронута одна строка.
     *
     * Протухшая аренда перехватывается по времени: воркер, упавший во время
     * выдачи, не блокирует заказ навсегда.
     */
    public function acquireDeliveryLease(int $orderId, string $token, int $seconds, string $owner): ?DeliveryLease
    {
        $affected = $this->db->table('orders')
            ->where('id', $orderId)
            ->whereIn('status', array_map(
                static fn (OrderStatus $status): string => $status->value,
                OrderStatus::awaitingDelivery(),
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

        return $affected === 1 ? new DeliveryLease($orderId, $token) : null;
    }

    /**
     * Снять аренду. Условие по токену обязательно: аренда могла протухнуть
     * и уйти другому воркеру, и снимать её тогда уже не наше дело.
     */
    public function releaseDeliveryLease(DeliveryLease $lease): void
    {
        $this->db->table('orders')
            ->where('id', $lease->orderId)
            ->where('lease_token', $lease->token)
            ->update(['lease_token' => null, 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    /**
     * Пометить заказ требующим ручного разбора. Живёт в репозитории, а не в
     * Action: весь доступ к таблицам — здесь (CLAUDE.md §1.2).
     */
    public function flagForReview(int $orderId, string $reason): void
    {
        $this->db->table('orders')->where('id', $orderId)->update([
            'needs_review' => true,
            'review_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * Сдвинуть момент следующей попытки — worklist подметальщика.
     */
    public function scheduleNextAction(int $orderId, int $delaySeconds): void
    {
        $this->db->table('orders')->where('id', $orderId)->update([
            'next_action_at' => now()->addSeconds($delaySeconds),
            'updated_at' => now(),
        ]);
    }

    /**
     * Следующий внешний идентификатор формата ord_00123 из контракта.
     *
     * Последовательность живёт в БД, а не в PHP: два параллельных процесса
     * иначе выдали бы один и тот же номер, и второй заказ упал бы на
     * orders_public_id_uq.
     */
    public function nextPublicId(): string
    {
        /** @var list<object{value: int|string}> $rows */
        $rows = $this->db->select("SELECT nextval('orders_public_id_seq') AS value");

        return sprintf('ord_%05d', (int) $rows[0]->value);
    }
}
