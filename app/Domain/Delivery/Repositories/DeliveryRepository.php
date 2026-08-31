<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Repositories;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\DTO\ClaimedKey;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Database\ConnectionInterface;

final readonly class DeliveryRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Запись факта выдачи из пула.
     *
     * Нарушение deliveries_order_uq здесь означает «уже выдано» и обрабатывается
     * вызывающим кодом СНАРУЖИ транзакции: после 23505 транзакция PostgreSQL
     * уже в состоянии abort.
     */
    public function recordFromPool(Order $order, ClaimedKey $key): int
    {
        /** @var int $id */
        $id = $this->db->table('deliveries')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'supply_mode' => SupplyMode::Pool->value,
            'license_key_id' => $key->id,
            'code_encrypted' => $key->encryptedCode,
            'code_hash' => $key->codeHash,
            'code_last4' => $key->codeLast4,
            'created_at' => now(),
        ]);

        return $id;
    }

    public function findByOrderId(int $orderId): ?Delivery
    {
        return Delivery::query()->where('order_id', $orderId)->first();
    }
}
