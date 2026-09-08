<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\History\Actions\ReconstructOrder;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Http\Requests\ShowOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Заказ сейчас или на прошедший момент.
 *
 * Один маршрут, а не два: «состояние заказа» — один вопрос, у которого есть
 * необязательный параметр «на когда». Разведя их по разным адресам, мы
 * получили бы два ответа разной формы на один и тот же вопрос.
 */
final class ShowOrderController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ReconstructOrder $history,
    ) {}

    public function __invoke(ShowOrderRequest $request, string $publicId): JsonResponse
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            throw new NotFoundHttpException("Order {$publicId} not found");
        }

        $asOf = $request->asOf();

        if ($asOf === null) {
            return OrderResource::make($order)->response();
        }

        return response()->json(['data' => $this->history->execute($order, $asOf)->toArray()]);
    }
}
