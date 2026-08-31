<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Платежи: инбокс вебхуков и отдельная проекция состояния оплаты.
 *
 * Проекция намеренно отделена от orders.status. Это точная политика для событий
 * «вне порядка»: поздний failed обязан быть записан как правда о деньгах, но не
 * имеет права отменить уже отданный клиенту код (CLAUDE.md §5.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('event_id', 128);

            // БЕЗ внешнего ключа сознательно: вебхук может прийти раньше заказа,
            // и FK превратил бы штатный сценарий в отказ приёма события.
            $table->string('order_public_id', 32);

            $table->string('status', 16);
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();

            // occurred_at — время события у платёжки (created_at из контракта),
            // received_at — время у нас. Порядок определяется по первому.
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('received_at')->useCurrent();

            $table->timestampTz('applied_at')->nullable();
            $table->string('process_state', 32)->default('pending');
            $table->smallInteger('attempts')->default(0);

            // Отпечаток тела: повтор того же event_id с ДРУГИМ содержимым — это не
            // честный дубль, а инцидент, и терять его молча нельзя.
            $table->char('body_fingerprint', 64);
            $table->jsonb('payload');
        });

        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_status_chk
            CHECK (status IN ('paid','failed','unknown'))");
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_process_state_chk
            CHECK (process_state IN (
                'pending','applied','duplicate_event','duplicate_paid','stale',
                'order_missing','amount_mismatch','ignored_final','malformed'))");

        // Идемпотентность приёма вебхука.
        DB::statement('CREATE UNIQUE INDEX payment_events_event_id_uq ON payment_events (event_id)');

        // Признание оплаты идемпотентно ПО ЗАКАЗУ, а не по event_id: критерий
        // приёмки допускает 50 вебхуков с РАЗНЫМИ event_id, и при ключе по событию
        // журнал получил бы 50 проводок, оставаясь «сбалансированным».
        DB::statement("CREATE UNIQUE INDEX payment_events_one_applied_paid_uq
            ON payment_events (order_public_id)
            WHERE status = 'paid' AND process_state = 'applied'");

        // Инбокс он же outbox: восстановление ведётся отсюда, а не от статуса заказа.
        // Множество непринятых мало по определению, поэтому индекс частичный.
        DB::statement('CREATE INDEX payment_events_unapplied_idx ON payment_events (received_at)
            WHERE applied_at IS NULL');

        DB::statement('CREATE INDEX payment_events_order_idx
            ON payment_events (order_public_id, occurred_at)');

        Schema::create('order_payment_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->primary();
            $table->string('state', 16);

            // Кортеж монотонности: устаревшее событие отбрасывается upsert'ом,
            // а не «последний выигрывает».
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('event_row_id');
            $table->string('last_event_id', 128);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('event_row_id')->references('id')->on('payment_events')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE order_payment_states ADD CONSTRAINT order_payment_states_state_chk
            CHECK (state IN ('paid','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_states');
        Schema::dropIfExists('payment_events');
    }
};
