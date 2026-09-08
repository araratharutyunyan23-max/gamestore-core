<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Единственная форма представления заказа наружу. Используется и на создании,
 * и на чтении: разная форма ответа для одного ресурса — источник расхождений
 * контракта.
 *
 * Внутренние bigint-идентификаторы наружу не отдаются вообще, только public_id.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->public_id,
            'status' => $order->status->value,
            // Поля sku и product_name остаются ради контракта первого этапа:
            // по нему уже интегрировались, и убрать их молча нельзя. Источник
            // истины о товарах заказа — items.
            'sku' => $order->sku,
            'product_name' => $order->product->name,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'payment_state' => $order->paymentState?->state->value,
            // Код отдаётся ПО ПОЗИЦИЯМ. Верхнеуровневого delivery больше нет:
            // выдач у заказа столько же, сколько позиций, и отдавать «какую-то
            // одну» значит показывать покупателю чужой код из его же заказа.
            'items' => $order->items->map(static fn (OrderItem $item): array => [
                'line_no' => $item->line_no,
                'sku' => $item->sku,
                'product_name' => $item->product->name,
                'amount_minor' => $item->unit_amount_minor,
                'status' => $item->status->value,
                // Код появляется только когда он действительно выдан.
                'code' => $item->delivery?->code_encrypted,
                'delivered_at' => $item->delivered_at?->toIso8601String(),
            ])->all(),
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }
}
