<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

/**
 * Захваченный, но ещё не выданный ключ.
 *
 * Хранит шифртекст, а не открытый код: расшифровка происходит ровно в двух
 * местах — при записи выдачи и при отдаче клиенту через ресурс.
 */
final readonly class ClaimedKey
{
    public function __construct(
        public int $id,
        public string $encryptedCode,
        public string $codeHash,
        public string $codeLast4,
    ) {}
}
