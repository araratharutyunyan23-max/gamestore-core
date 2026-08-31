<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTO;

/**
 * Вход сервиса создания заказа. Собирается в контроллере из FormRequest,
 * чтобы домен не зависел от HTTP.
 */
final readonly class CreateOrderCommand
{
    public function __construct(
        public string $sku,
        public string $idempotencyKey,
    ) {}
}
