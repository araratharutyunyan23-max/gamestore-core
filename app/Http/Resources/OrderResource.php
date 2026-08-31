<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
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
            'sku' => $order->sku,
            'product_name' => $order->product->name,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'payment_state' => $order->paymentState?->state->value,
            // Код отдаётся только когда он действительно выдан. До этого поля нет,
            // а не null: отсутствие поля читается однозначно.
            'delivery' => $order->delivery === null ? null : [
                'code' => $order->delivery->code_encrypted,
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ],
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }
}
