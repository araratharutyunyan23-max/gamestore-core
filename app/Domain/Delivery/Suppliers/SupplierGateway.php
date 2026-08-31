<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\DTO\IssueRequest;
use App\Domain\Delivery\DTO\SupplierResponse;
use App\Domain\Delivery\Enums\SupplierName;

/**
 * Контракт поставщика.
 *
 * Интерфейс здесь оправдан не «на будущее», а прямо сейчас: реализаций три —
 * HTTP-клиент и два декоратора поверх него (повторы и переключение на второго
 * поставщика). Без общего типа декораторы было бы не собрать.
 *
 * Ни один метод НЕ бросает исключений. Это главное правило контракта: сетевой
 * сбой обязан прийти значением, иначе вызывающий код вынужден угадывать, выдан
 * код или нет.
 */
interface SupplierGateway
{
    public function name(): SupplierName;

    public function issue(IssueRequest $request): SupplierResponse;

    /** Неизменяющий вопрос «что с этим запросом». */
    public function probe(string $requestId): SupplierResponse;

    /** Обязательство поставщика никогда не выдавать код по этому request_id. */
    public function seal(string $requestId): SupplierResponse;
}
