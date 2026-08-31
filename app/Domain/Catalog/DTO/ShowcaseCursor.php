<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTO;

/**
 * Курсор keyset-пагинации.
 *
 * OFFSET на больших списках заставляет базу прочитать и выбросить все
 * пропускаемые строки: страница 200 стоит дороже страницы 2 линейно.
 * Курсор по (price_minor, id) стоит одинаково на любой странице, потому что
 * это ровно префикс индекса — база сразу прыгает в нужное место.
 *
 * Пара, а не одна цена: цены не уникальны, и по одной цене страницы
 * склеивались бы или теряли строки на границе.
 */
final readonly class ShowcaseCursor
{
    public function __construct(
        public int $priceMinor,
        public int $id,
    ) {}

    public static function decode(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $parts = explode(':', $raw, 2);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return new self((int) $parts[0], (int) $parts[1]);
    }

    public function encode(): string
    {
        return $this->priceMinor.':'.$this->id;
    }
}
