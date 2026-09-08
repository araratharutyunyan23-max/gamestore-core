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
        return $this->purchasableBySkus([$sku])[0];
    }

    /**
     * Товары для списка SKU — ОДНИМ запросом.
     *
     * Заказ из десяти позиций не имеет права стоить десять выборок каталога:
     * это тот самый N+1, запрещённый CLAUDE.md §4, и растёт он ровно там, где
     * дороже всего — на создании заказа.
     *
     * Порядок результата повторяет порядок аргумента, а не порядок базы:
     * номера строк заказа обязаны совпасть с тем, что прислал покупатель.
     * Повторяющийся SKU разрешён — две одинаковые позиции это два разных
     * товара к выдаче, а не ошибка ввода.
     *
     * @param  non-empty-list<string>  $skus
     * @return non-empty-list<Product>
     *
     * @throws ProductNotPurchasable
     */
    public function purchasableBySkus(array $skus): array
    {
        $found = Product::query()
            ->whereIn('sku', array_values(array_unique($skus)))
            ->where('is_active', true)
            ->get()
            ->keyBy('sku');

        $ordered = [];

        foreach ($skus as $sku) {
            $product = $found->get($sku);

            if (! $product instanceof Product) {
                throw ProductNotPurchasable::sku($sku);
            }

            $ordered[] = $product;
        }

        return $ordered;
    }
}
