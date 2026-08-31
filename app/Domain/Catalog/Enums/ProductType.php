<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** Типы товаров из каталога задания. Значения совпадают с products_type_chk. */
enum ProductType: string
{
    case Topup = 'topup';
    case Key = 'key';
    case Subscription = 'subscription';
    case GiftCard = 'giftcard';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
