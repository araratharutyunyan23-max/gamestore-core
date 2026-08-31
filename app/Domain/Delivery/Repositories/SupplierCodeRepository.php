<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Repositories;

use App\Domain\Delivery\DTO\CapturedCode;
use App\Domain\Delivery\Enums\CodeDisposition;
use App\Domain\Delivery\Enums\SupplierName;
use App\Models\LicenseKey;
use Illuminate\Database\ConnectionInterface;

/**
 * Журнал кодов, полученных от поставщика. Только на добавление.
 *
 * Код записывается сюда ОТДЕЛЬНОЙ короткой транзакцией сразу после ответа
 * поставщика — до привязки к заказу, до проводок, до смены статуса.
 *
 * Причина ровно одна: любое исключение в бизнес-транзакции откатит привязку,
 * и уже КУПЛЕННЫЙ код исчезнет. Поставщику при этом всё равно — он его выдал
 * и списал. Отделив запись кода от его применения, мы гарантируем, что
 * повторный прогон задачи доведёт заказ тем же кодом, а не купит второй.
 */
final readonly class SupplierCodeRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return bool false, если код по этому request_id уже был записан
     */
    public function capture(string $requestId, SupplierName $supplier, string $code): bool
    {
        return $this->db->table('supplier_issued_codes')->insertOrIgnore([
            'request_id' => $requestId,
            'supplier' => $supplier->value,
            'code_encrypted' => encrypt($code),
            'code_hash' => LicenseKey::fingerprint($code),
            'code_last4' => LicenseKey::last4($code),
            'disposition' => CodeDisposition::Unassigned->value,
            'received_at' => now(),
        ]) === 1;
    }

    public function findByRequestId(string $requestId): ?CapturedCode
    {
        /** @var list<object{id: int, code_encrypted: string, code_hash: string, code_last4: string}> $rows */
        $rows = $this->db->select(
            'SELECT id, code_encrypted, code_hash, code_last4 FROM supplier_issued_codes WHERE request_id = ?',
            [$requestId],
        );

        if ($rows === []) {
            return null;
        }

        return new CapturedCode(
            id: (int) $rows[0]->id,
            encryptedCode: $rows[0]->code_encrypted,
            codeHash: $rows[0]->code_hash,
            codeLast4: $rows[0]->code_last4,
        );
    }

    public function assign(int $codeId, CodeDisposition $disposition): void
    {
        $this->db->table('supplier_issued_codes')->where('id', $codeId)->update([
            'disposition' => $disposition->value,
        ]);
    }
}
