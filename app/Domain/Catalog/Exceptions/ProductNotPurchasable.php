<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class ProductNotPurchasable extends DomainException
{
    public static function sku(string $sku): self
    {
        return new self("SKU {$sku} is unknown or not available for purchase");
    }
}
