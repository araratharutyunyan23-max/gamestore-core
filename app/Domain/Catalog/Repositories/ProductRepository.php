<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Repositories;

use App\Domain\Catalog\Exceptions\ProductNotPurchasable;
use App\Models\Product;

/**
 * Весь доступ к каталогу. Наружу отдаёт точные типы: Eloquent-методы поиска
 * возвращают mixed, и это mixed не должен доходить до сервисов (CLAUDE.md §2.1).
 */
final class ProductRepository
{
    /**
     * @throws ProductNotPurchasable
     */
    public function purchasableBySku(string $sku): Product
    {
        $product = Product::query()->where('sku', $sku)->first();

        if ($product === null || ! $product->is_active) {
            throw ProductNotPurchasable::sku($sku);
        }

        return $product;
    }
}
