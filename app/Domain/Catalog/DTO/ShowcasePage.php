<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTO;

/**
 * Страница витрины: товары и остатки, собранные ДВУМЯ запросами.
 *
 * Не одним с JOIN: таблица остатков — самая перезаписываемая в системе, и
 * присоединение её к запросу с LIMIT ломает Index Only Scan по покрывающему
 * индексу. И не N+1: остатки берутся одним запросом по массиву идентификаторов.
 */
final readonly class ShowcasePage
{
    /**
     * @param  list<ShowcaseItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
