<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use App\Domain\Delivery\Enums\SupplierName;

/**
 * Детерминированный идентификатор обращения к поставщику.
 *
 * Строится из (заказ, поставщик, эпоха) и НЕ зависит от номера сетевой попытки.
 * Это ключевое: повтор после таймаута обязан идти с тем же идентификатором,
 * иначе поставщик считает его новым запросом и выдаёт ВТОРОЙ код.
 *
 * Эпоха растёт только после доказанного «не выдано». Пока судьба предыдущего
 * вызова неизвестна, менять её нельзя — это и есть путь к двойной выдаче.
 */
final readonly class RequestId
{
    private function __construct(public string $value) {}

    public static function for(string $orderPublicId, SupplierName $supplier, int $epoch): self
    {
        return new self(sprintf('req_%s-%s-%d', $orderPublicId, $supplier->value, $epoch));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
