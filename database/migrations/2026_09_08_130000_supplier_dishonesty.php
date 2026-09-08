<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Недобросовестный поставщик: новые классы расхождений и отвергнутый код.
 *
 * Уникальность кода в журнале полученных от поставщика уже была с первого
 * этапа (`supplier_issued_codes_hash_uq`) — проверено, дублировать её здесь
 * не нужно. Не хватало не запрета, а ТРАКТОВКИ: дубль упирался в этот индекс
 * или в `deliveries_code_hash_uq`, а нарушение второго читается как «уже
 * выдано» — то есть позиция считалась бы выданной, не имея выдачи вовсе.
 *
 * Поэтому здесь заводятся классы расхождений и состояние отвергнутого кода:
 * дубль обязан быть НАЗВАН тем, чем он является, а не проглочен как успех.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE supplier_issued_codes DROP CONSTRAINT IF EXISTS supplier_issued_codes_disposition_chk');
        DB::statement("ALTER TABLE supplier_issued_codes ADD CONSTRAINT supplier_issued_codes_disposition_chk
            CHECK (disposition IN ('unassigned','for_order','surplus','rejected'))");

        DB::statement('ALTER TABLE reconciliation_findings DROP CONSTRAINT reconciliation_findings_kind_chk');
        DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_kind_chk
            CHECK (kind IN (
                'paid_not_delivered','delivered_not_paid','amount_mismatch','orphan_event',
                'unapplied_payment','stuck_delivery','attempt_unknown','stock_drift',
                'duplicate_code','ledger_unbalanced','late_payment_failure','payment_revoked',
                'event_id_reuse','supplier_possible_double_charge','supplier_surplus_code',
                'supplier_returned_duplicate_code','supplier_returned_foreign_code',
                'awaiting_restock'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_issued_codes DROP CONSTRAINT IF EXISTS supplier_issued_codes_disposition_chk');

        DB::statement('ALTER TABLE reconciliation_findings DROP CONSTRAINT reconciliation_findings_kind_chk');
        DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_kind_chk
            CHECK (kind IN (
                'paid_not_delivered','delivered_not_paid','amount_mismatch','orphan_event',
                'unapplied_payment','stuck_delivery','attempt_unknown','stock_drift',
                'duplicate_code','ledger_unbalanced','late_payment_failure','payment_revoked',
                'event_id_reuse','supplier_possible_double_charge','supplier_surplus_code',
                'awaiting_restock'))");
    }
};
