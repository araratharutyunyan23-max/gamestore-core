<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repositories;

use App\Models\Order;
use Illuminate\Database\ConnectionInterface;

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
