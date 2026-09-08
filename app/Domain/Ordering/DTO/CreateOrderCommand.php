<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTO;

use InvalidArgumentException;

/**
 * Вход сервиса создания заказа. Собирается в контроллере из FormRequest,
 * чтобы домен не зависел от HTTP.
 *
 * Несёт СПИСОК SKU: со второго этапа покупатель берёт несколько товаров
 * в одном заказе. Заказ из одного товара — частный случай списка длиной один,
 * а не отдельный путь: два пути разошлись бы ровно в том переходе, который
 * никто не догадался проверить.
 */
final readonly class CreateOrderCommand
{
    /**
     * @param  non-empty-list<string>  $skus
     */
    private function __construct(
        public array $skus,
        public string $idempotencyKey,
    ) {}

    public static function forSku(string $sku, string $idempotencyKey): self
    {
        return new self([$sku], $idempotencyKey);
    }

    /**
     * @param  list<string>  $skus
     */
    public static function forSkus(array $skus, string $idempotencyKey): self
    {
        if ($skus === []) {
            // Пустой заказ — это заказ на ноль рублей, который нечего выдавать
            // и не за что возвращать. Отбивается здесь, а не в базе: до базы
            // такое доходить не должно.
            throw new InvalidArgumentException('Order must contain at least one item');
        }

        return new self(array_values($skus), $idempotencyKey);
    }
}
