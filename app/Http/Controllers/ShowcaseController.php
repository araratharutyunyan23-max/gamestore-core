<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\DTO\ShowcaseItem;
use App\Domain\Catalog\Repositories\ShowcaseRepository;
use App\Http\Requests\ShowcaseRequest;
use Illuminate\Http\JsonResponse;

final class ShowcaseController
{
    public function __construct(private readonly ShowcaseRepository $showcase) {}

    public function __invoke(ShowcaseRequest $request): JsonResponse
    {
        $page = $this->showcase->page($request->type(), $request->cursor(), $request->limit());

        return response()->json([
            'data' => array_map(static fn (ShowcaseItem $item): array => $item->toArray(), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
