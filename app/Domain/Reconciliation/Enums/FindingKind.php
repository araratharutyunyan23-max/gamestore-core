<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Enums;

/**
 * Классы аномалий сверки. Значения совпадают с reconciliation_findings_kind_chk.
 *
 * Severity здесь не косметика: ожидание пополнения склада — это warning, а не
 * инцидент. Если сделать его critical, критерий приёмки №6 («пустой остаток —
 * восстановимое состояние») навсегда покрасит систему в «нездорова».
 */
enum FindingKind: string
{
    case PaidNotDelivered = 'paid_not_delivered';
    case DeliveredNotPaid = 'delivered_not_paid';
    case AmountMismatch = 'amount_mismatch';

    /** Событие есть, заказа нет — вебхук обогнал создание заказа. */
    case OrphanEvent = 'orphan_event';

    /** Событие принято, но не применено: потерянный dispatch или упавший воркер. */
    case UnappliedPayment = 'unapplied_payment';

    case StuckDelivery = 'stuck_delivery';

    /** Попытка висит в неразрешённом состоянии — судьба кода у поставщика неизвестна. */
    case AttemptUnknown = 'attempt_unknown';

    /** Счётчик остатка разошёлся с реальным числом ключей. */
    case StockDrift = 'stock_drift';

    case DuplicateCode = 'duplicate_code';
    case LedgerUnbalanced = 'ledger_unbalanced';

    /** Отмена платежа пришла после того, как товар уже выдан. */
    case LatePaymentFailure = 'late_payment_failure';

    /** Оплата отозвана до начала выдачи — доставку останавливаем. */
    case PaymentRevoked = 'payment_revoked';

    /** Тот же event_id с другим телом. */
    case EventIdReuse = 'event_id_reuse';

    /** Возможно, заплатили поставщику дважды: клиенту ушёл один код, но A мог выдать свой. */
    case SupplierPossibleDoubleCharge = 'supplier_possible_double_charge';

    case SupplierSurplusCode = 'supplier_surplus_code';

    /** Не инцидент: заказ ждёт пополнения склада. */
    case AwaitingRestock = 'awaiting_restock';

    public function severity(): FindingSeverity
    {
        // Перечислено полностью: новый вид находки обязан получить severity
        // осознанно, а не унаследовать critical по умолчанию.
        return match ($this) {
            self::AwaitingRestock => FindingSeverity::Warning,
            self::PaidNotDelivered, self::DeliveredNotPaid, self::AmountMismatch,
            self::OrphanEvent, self::UnappliedPayment, self::StuckDelivery,
            self::AttemptUnknown, self::StockDrift, self::DuplicateCode,
            self::LedgerUnbalanced, self::LatePaymentFailure, self::PaymentRevoked,
            self::EventIdReuse, self::SupplierPossibleDoubleCharge,
            self::SupplierSurplusCode => FindingSeverity::Critical,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
