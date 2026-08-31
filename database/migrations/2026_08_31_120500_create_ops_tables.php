<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Эксплуатация: аудит переходов, находки сверки, ключи идемпотентности.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Аудит переходов. Из него строится доказательство «выдача ровно одна»,
        // не зависящее от текущего состояния строки заказа.
        Schema::create('order_status_transitions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 64)->nullable();
            $table->string('actor', 32)->default('system');
            $table->string('trace_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX order_status_transitions_order_idx
            ON order_status_transitions (order_id, id)');
        DB::statement("CREATE INDEX order_status_transitions_delivered_idx
            ON order_status_transitions (order_id) WHERE to_status = 'delivered'");

        Schema::create('reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('kind', 48);
            $table->string('severity', 16)->default('critical');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('subject_ref', 128)->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('detected_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_kind_chk
            CHECK (kind IN (
                'paid_not_delivered','delivered_not_paid','amount_mismatch','orphan_event',
                'unapplied_payment','stuck_delivery','attempt_unknown','stock_drift',
                'duplicate_code','ledger_unbalanced','late_payment_failure','payment_revoked',
                'event_id_reuse','supplier_possible_double_charge','supplier_surplus_code',
                'awaiting_restock'))");
        DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_severity_chk
            CHECK (severity IN ('critical','warning'))");

        // Одна открытая находка на (вид, субъект): повторный прогон сверки не
        // должен размножать одну и ту же аномалию.
        DB::statement('CREATE UNIQUE INDEX reconciliation_findings_open_uq
            ON reconciliation_findings (kind, subject_ref) WHERE resolved_at IS NULL');
        DB::statement('CREATE INDEX reconciliation_findings_open_idx
            ON reconciliation_findings (severity, detected_at) WHERE resolved_at IS NULL');

        // Идемпотентность с ЖИЗНЕННЫМ ЦИКЛОМ, а не фактом существования:
        // «захват без завершения» после падения воркера навсегда заблокировал бы
        // повтор, а вызывающий счёл бы это за «уже сделано» (CLAUDE.md §5.4).
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->string('scope', 32);
            $table->string('key', 160);
            $table->string('state', 16)->default('claimed');
            $table->string('owner', 64)->nullable();
            $table->timestampTz('claimed_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->primary(['scope', 'key']);
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_state_chk
            CHECK (state IN ('claimed','completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('reconciliation_findings');
        Schema::dropIfExists('order_status_transitions');
    }
};
