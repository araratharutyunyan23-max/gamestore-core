<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ordering\Repositories\OrderRepository;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowOrderController
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function __invoke(string $publicId): JsonResponse
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            throw new NotFoundHttpException("Order {$publicId} not found");
        }

        return OrderResource::make($order)->response();
    }
}
