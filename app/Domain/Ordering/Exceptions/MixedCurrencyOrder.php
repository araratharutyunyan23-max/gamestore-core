<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use DomainException;

/**
 * Заказ в двух валютах отбивается на входе.
 *
 * Не придирка: у заказа одна колонка currency, а итог считается сложением
 * позиций. Сложить рубли с долларами нельзя — получившееся число не значит
 * ничего, и именно оно потом поедет в вебхук, в журнал и в сверку.
 */
final class MixedCurrencyOrder extends DomainException
{
    /**
     * @param  list<string>  $currencies
     */
    public static function of(array $currencies): self
    {
        return new self('Order mixes currencies: '.implode(', ', $currencies));
    }
}
