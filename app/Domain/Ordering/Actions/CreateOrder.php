<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Catalog\Exceptions\ProductNotPurchasable;
use App\Domain\Catalog\Repositories\ProductRepository;
use App\Domain\Ordering\DTO\CreateOrderCommand;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Payments\Actions\DrainUnappliedPayments;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Создание заказа по SKU.
 *
 * Идемпотентность держится на индексе orders_idempotency_key_uq, а не на
 * предварительной проверке: между SELECT и INSERT помещается конкурент.
 * Поэтому проверка «уже есть» — это оптимизация, а настоящая гарантия —
 * обработка 23505.
 */
final readonly class CreateOrder
{
    public function __construct(
        private ProductRepository $products,
        private OrderRepository $orders,
        private DrainUnappliedPayments $drain,
    ) {}

    /**
     * @throws ProductNotPurchasable
     */
    public function execute(CreateOrderCommand $command): Order
    {
        $existing = $this->orders->findByIdempotencyKey($command->idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $product = $this->products->purchasableBySku($command->sku);

        try {
            Order::query()->create([
                'public_id' => $this->orders->nextPublicId(),
                'idempotency_key' => $command->idempotencyKey,
                'product_id' => $product->id,
                // Цена и SKU фиксируются снимком: каталог меняется, история — нет.
                'sku' => $product->sku,
                'amount_minor' => $product->price_minor,
                'currency' => $product->currency,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Конкурент успел первым с тем же ключом идемпотентности.
            // Это штатный исход повторной отправки, а не ошибка.
        }

        $order = $this->orders->findByIdempotencyKey($command->idempotencyKey);

        if ($order === null) {
            throw new RuntimeException(
                "Order for idempotency key {$command->idempotencyKey} vanished right after creation",
            );
        }

        // Вебхук мог прийти раньше заказа. Усыновление идёт ПОСЛЕ фиксации
        // заказа, а не внутри транзакции создания: внутри строка ещё не видна
        // другим соединениям, и воркер, взявший задачу, заказа бы не нашёл.
        $this->drain->forOrder($order->public_id);

        return $order;
    }
}
