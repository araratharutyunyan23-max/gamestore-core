<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Exceptions\ProductNotPurchasable;
use App\Domain\Ordering\Actions\CreateOrder;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class CreateOrderController
{
    public function __construct(private readonly CreateOrder $action) {}

    /**
     * @throws ProductNotPurchasable
     */
    public function __invoke(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->action->execute($request->toCommand());

        return OrderResource::make($order)->response()->setStatusCode(201);
    }
}
