<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repositories;

use App\Domain\Ordering\DTO\DeliveryLease;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\MixedCurrencyOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

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
            ->with(['product', 'delivery', 'paymentState', 'items.product'])
            ->where('public_id', $publicId)
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        return Order::query()
            ->with(['product', 'delivery', 'paymentState', 'items.product'])
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
            ->with(['product', 'delivery', 'paymentState', 'items.product'])
            ->where('id', $id)
            ->lock('for no key update')
            ->first();
    }

    /**
     * Заказы, застрявшие в ожидании выдачи.
     *
     * Выборка идёт по частичному индексу orders_worklist_idx, который покрывает
     * только невыполненные заказы и потому не растёт вместе с историей.
     * Заказы под живой арендой исключаются: их прямо сейчас кто-то выдаёт.
     *
     * @return list<object{public_id: string, status: string}>
     */
    public function stuckAwaitingDelivery(int $olderThanMinutes, int $limit): array
    {
        /** @var list<object{public_id: string, status: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT public_id, status
              FROM orders
             WHERE status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
               AND status_changed_at < now() - make_interval(mins => ?)
               AND (lease_expires_at IS NULL OR lease_expires_at <= now())
             ORDER BY next_action_at
             LIMIT ?
        SQL, [$olderThanMinutes, $limit]);

        return $rows;
    }

    /**
     * Создать заказ. Нарушение orders_idempotency_key_uq означает, что
     * конкурент успел первым с тем же ключом, и это штатный исход повторной
     * отправки — обрабатывается вызывающим кодом.
     */
    /**
     * Создать заказ вместе с позициями.
     *
     * Одной транзакцией, потому что заказ без позиций — это оплаченный заказ,
     * в котором нечего выдавать. Сумма заказа обязана равняться сумме позиций,
     * и это держит отложенный триггер order_items_total_matches: он проверяет
     * равенство на коммите, когда все строки уже на месте.
     *
     * @param  non-empty-list<Product>  $products
     *
     * @throws MixedCurrencyOrder
     */
    public function create(string $publicId, string $idempotencyKey, array $products): void
    {
        $currencies = array_values(array_unique(array_map(
            static fn (Product $product): string => $product->currency,
            $products,
        )));

        if (count($currencies) > 1) {
            throw MixedCurrencyOrder::of($currencies);
        }

        $total = array_sum(array_map(
            static fn (Product $product): int => $product->price_minor,
            $products,
        ));

        $this->db->transaction(function () use ($publicId, $idempotencyKey, $products, $currencies, $total): void {
            $order = Order::query()->create([
                'public_id' => $publicId,
                'idempotency_key' => $idempotencyKey,
                // Снимок ПЕРВОЙ позиции. Колонки остаются ради кода первого
                // этапа; источник истины о товарах — order_items.
                'product_id' => $products[0]->id,
                'sku' => $products[0]->sku,
                'amount_minor' => $total,
                'currency' => $currencies[0],
            ]);

            // Одной вставкой, а не строкой на позицию: заказ из десяти товаров
            // не имеет права стоить десять round-trip до базы.
            $now = Carbon::now();
            $rows = [];
            $lineNo = 0;

            foreach ($products as $product) {
                $lineNo++;
                $rows[] = [
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'line_no' => $lineNo,
                    // Цена и SKU фиксируются снимком: каталог меняется, история — нет.
                    'sku' => $product->sku,
                    'unit_amount_minor' => $product->price_minor,
                    'currency' => $product->currency,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->db->table('order_items')->insert($rows);
        });
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
     * Увеличить эпоху выдачи.
     *
     * Эпоха входит в request_id, поэтому её рост означает право получить НОВЫЙ
     * код. Двигать её можно только после ДОКАЗАННОГО отсутствия выдачи — пока
     * судьба предыдущего обращения неизвестна, новая эпоха означала бы вторую
     * покупку.
     *
     * Инкремент в самой базе, а не чтение-плюс-запись: два воркера иначе
     * прочитали бы одно значение и получили одинаковый request_id.
     */
    public function bumpDeliveryEpoch(int $orderId): void
    {
        $this->db->table('orders')->where('id', $orderId)->update([
            'delivery_epoch' => $this->db->raw('delivery_epoch + 1'),
            'updated_at' => now(),
        ]);
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
