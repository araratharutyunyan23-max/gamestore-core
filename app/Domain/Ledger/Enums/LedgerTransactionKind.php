<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerTransactionKind: string
{
    case PaymentCaptured = 'payment_captured';
    case OrderDelivered = 'order_delivered';
    case PaymentReversed = 'payment_reversed';
    case SupplierSurplus = 'supplier_surplus';

    /**
     * Ключ идемпотентности денежной операции.
     *
     * Для признания оплаты ключ строится ПО ЗАКАЗУ, а не по event_id: контракт
     * обещает тот же event_id на повтор, но критерий приёмки допускает 50 вебхуков
     * с РАЗНЫМИ event_id. При ключе по событию журнал получил бы 50 проводок,
     * оставаясь при этом идеально сбалансированным (CLAUDE.md §5.4).
     */
    public function idempotencyKeyFor(string $orderPublicId): string
    {
        return $this->value.':'.$orderPublicId;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
