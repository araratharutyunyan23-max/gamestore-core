<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

/**
 * Код, уже полученный от поставщика и записанный в журнал обязательств.
 *
 * Отдельный тип, а не голый object из выборки: обращение к магическим
 * свойствам возвращает mixed, и оно расползлось бы по всему пути выдачи.
 */
final readonly class CapturedCode
{
    public function __construct(
        public int $id,
        public string $encryptedCode,
        public string $codeHash,
        public string $codeLast4,
    ) {}
}
