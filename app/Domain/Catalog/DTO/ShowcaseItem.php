<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTO;

use App\Domain\Catalog\Enums\SupplyMode;

final readonly class ShowcaseItem
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public SupplyMode $supplyMode,
        /**
         * Остаток известен только для товаров из собственного пула.
         * У товара от поставщика его нет по определению: доступность
         * выясняется вызовом, и показывать выдуманное число — врать клиенту.
         */
        public ?int $availableCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'price_minor' => $this->priceMinor,
            'currency' => $this->currency,
            'available' => $this->availableCount,
        ];
    }
}
