<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * План счетов. Шесть, а не одиннадцать: возвраты, комиссии и выплаты поставщикам
 * описаны в README как «куда расти». Их присутствие в журнале без соответствующего
 * состояния заказа само порождает баги — возвращённый заказ остаётся в
 * восстановимом статусе, и подметальщик выдаёт товар клиенту, которому уже
 * вернули деньги.
 */
enum LedgerAccount: string
{
    /** Актив: деньги, которые должен перевести платёжный шлюз. */
    case GatewayReceivable = 'gateway_receivable';

    /** Обязательство: оплачено, но товар ещё не отдан. Ключевой счёт сверки. */
    case CustomerPrepayment = 'customer_prepayment';

    /** Обязательство: деньги пришли, но применить их не к чему (дубль платежа). */
    case SuspenseUnapplied = 'suspense_unapplied';

    /** Доход, признанный в момент выдачи. */
    case Revenue = 'revenue';

    /** Себестоимость выданного кода. */
    case Cogs = 'cogs';

    /** Расход: код у поставщика мог сгореть при неразрешённом таймауте. */
    case SupplierLeakage = 'supplier_leakage';

    public function kind(): LedgerAccountKind
    {
        return match ($this) {
            self::GatewayReceivable => LedgerAccountKind::Asset,
            self::CustomerPrepayment, self::SuspenseUnapplied => LedgerAccountKind::Liability,
            self::Revenue => LedgerAccountKind::Income,
            self::Cogs, self::SupplierLeakage => LedgerAccountKind::Expense,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
