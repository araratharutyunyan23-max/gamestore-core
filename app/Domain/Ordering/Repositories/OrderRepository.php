<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repositories;

use App\Domain\Ordering\Exceptions\MixedCurrencyOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\ConnectionInterface;
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
            ->with(['product', 'paymentState', 'items.product', 'items.delivery'])
            ->where('public_id', $publicId)
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        return Order::query()
            ->with(['product', 'paymentState', 'items.product', 'items.delivery'])
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
            ->with(['product', 'paymentState', 'items.product', 'items.delivery'])
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
            SELECT orders.public_id, orders.status
              FROM orders
             WHERE orders.status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
               AND orders.status_changed_at < now() - make_interval(mins => ?)
               -- Аренда живёт на позициях, а не на заказе. Заказ, у которого
               -- хоть одну позицию прямо сейчас выдают, трогать рано: работа
               -- идёт, и повторный dispatch только займёт воркер впустую.
               AND NOT EXISTS (
                   SELECT 1 FROM order_items i
                    WHERE i.order_id = orders.id
                      AND i.lease_expires_at > now()
               )
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
