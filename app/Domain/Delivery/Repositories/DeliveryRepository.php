<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Repositories;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\DTO\ClaimedKey;
use App\Domain\Delivery\DTO\DeliveryTarget;
use App\Domain\Delivery\Enums\SupplierName;
use App\Models\Delivery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DeliveryRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Запись факта выдачи из пула.
     *
     * Нарушение deliveries_order_item_uq здесь означает «эта позиция уже
     * выдана» и обрабатывается вызывающим кодом СНАРУЖИ транзакции: после
     * 23505 транзакция PostgreSQL уже в состоянии abort.
     */
    public function recordFromPool(DeliveryTarget $target, ClaimedKey $key): int
    {
        /** @var int $id */
        $id = $this->db->table('deliveries')->insertGetId([
            'order_id' => $target->orderId,
            'order_item_id' => $target->itemId,
            'product_id' => $target->productId,
            'supply_mode' => SupplyMode::Pool->value,
            'license_key_id' => $key->id,
            'code_encrypted' => $key->encryptedCode,
            'code_hash' => $key->codeHash,
            'code_last4' => $key->codeLast4,
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * Запись факта выдачи кодом от поставщика.
     *
     * @throws UniqueConstraintViolationException
     */
    public function recordFromSupplier(
        DeliveryTarget $target,
        SupplierName $supplier,
        string $requestId,
        string $encryptedCode,
        string $codeHash,
        string $codeLast4,
    ): int {
        /** @var int $id */
        $id = $this->db->table('deliveries')->insertGetId([
            'order_id' => $target->orderId,
            'order_item_id' => $target->itemId,
            'product_id' => $target->productId,
            'supply_mode' => SupplyMode::Supplier->value,
            'supplier' => $supplier->value,
            'request_id' => $requestId,
            'code_encrypted' => $encryptedCode,
            'code_hash' => $codeHash,
            'code_last4' => $codeLast4,
            'created_at' => now(),
        ]);

        return $id;
    }

    public function findByOrderId(int $orderId): ?Delivery
    {
        return Delivery::query()->where('order_id', $orderId)->first();
    }

    public function findByOrderItemId(int $itemId): ?Delivery
    {
        return Delivery::query()->where('order_item_id', $itemId)->first();
    }
}
