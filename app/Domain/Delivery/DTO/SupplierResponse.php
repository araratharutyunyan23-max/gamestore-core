<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use App\Domain\Delivery\Enums\CallOutcome;

/**
 * Результат обращения к поставщику — и для выдачи, и для probe, и для seal.
 *
 * Класс никогда не бросает исключений наружу: сетевой сбой это тоже исход,
 * и он обязан быть представлен значением. Исключение здесь означало бы, что
 * вызывающий код обязан угадать, выдан код или нет — а именно этого
 * угадывания и нельзя допускать.
 */
final readonly class SupplierResponse
{
    public function __construct(
        public CallOutcome $outcome,
        public ?string $code = null,
        public ?int $httpStatus = null,
        public ?string $errorKind = null,
        public ?string $storeEpoch = null,
        public int $latencyMs = 0,
        /**
         * Товар, по которому поставщик ОБЪЯВИЛ выдачу.
         *
         * Именно объявил: проверить сам код мы не можем — что открывает
         * чужая строка символов, снаружи не узнать. Но сверить заявленный
         * товар с запрошенным можем, и этого достаточно, чтобы поймать
         * подмену, сделанную не со зла, а по ошибке маршрутизации у
         * поставщика.
         */
        public ?string $sku = null,
    ) {}

    public function hasCode(): bool
    {
        return $this->outcome === CallOutcome::Issued && $this->code !== null;
    }
}
