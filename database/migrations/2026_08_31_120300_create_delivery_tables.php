<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Выдача: факт выдачи, журнал попыток и append-only журнал полученных от
 * поставщика кодов.
 *
 * supplier_issued_codes отделён от бизнес-транзакции намеренно. Код пишется туда
 * микротранзакцией сразу после ответа поставщика, ДО привязки к заказу и проводок.
 * Любое исключение в бизнес-логике откатит привязку, но купленный код не потеряет
 * (CLAUDE.md §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');

            $table->string('supply_mode', 16);

            // Ровно один из двух источников: ключ из пула ИЛИ код от поставщика.
            $table->unsignedBigInteger('license_key_id')->nullable();
            $table->string('supplier', 8)->nullable();
            $table->string('request_id', 128)->nullable();

            $table->text('code_encrypted');
            $table->char('code_hash', 64);
            $table->char('code_last4', 4);

            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('license_key_id')->references('id')->on('license_keys')->restrictOnDelete();
        });

        // «Ровно одна выдача на заказ» — вот индекс, который это обеспечивает.
        // Нарушение 23505 по нему трактуется как «уже выдано», а не как ошибка.
        DB::statement('CREATE UNIQUE INDEX deliveries_order_uq ON deliveries (order_id)');

        // Один физический код не может уйти в два заказа — независимо от источника.
        DB::statement('CREATE UNIQUE INDEX deliveries_code_hash_uq ON deliveries (code_hash)');
        DB::statement('CREATE UNIQUE INDEX deliveries_license_key_uq ON deliveries (license_key_id)
            WHERE license_key_id IS NOT NULL');

        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_source_chk CHECK (
            (supply_mode = 'pool'     AND license_key_id IS NOT NULL AND request_id IS NULL)
         OR (supply_mode = 'supplier' AND license_key_id IS NULL     AND request_id IS NOT NULL))");

        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');

            $table->string('supplier', 8);

            // Детерминированный: req_{public_id}-{SUPPLIER}-{epoch}. Все сетевые
            // ретраи внутри эпохи идут с ТЕМ ЖЕ идентификатором.
            $table->string('request_id', 128);
            $table->smallInteger('epoch');

            $table->string('outcome', 24)->default('in_flight');

            // true только когда доказано, что запрос НЕ привёл к выдаче.
            // Единственное, что разрешает уход на второго поставщика.
            $table->boolean('definitive')->default(false);

            $table->integer('http_status')->nullable();
            $table->string('error_kind', 48)->nullable();
            $table->integer('latency_ms')->nullable();

            // Эпоха стора поставщика: если она сменилась между issue и probe,
            // ответ probe не авторитетен (заглушку перезапустили).
            $table->string('store_epoch', 64)->nullable();

            $table->smallInteger('probe_count')->default(0);
            $table->timestampTz('next_probe_at')->nullable();

            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->string('trace_id', 64)->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_outcome_chk
            CHECK (outcome IN (
                'in_flight','succeeded','failed','timeout','unknown','sealed','abandoned','superseded'))");
        DB::statement("ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_supplier_chk
            CHECK (supplier IN ('A','B'))");

        // Один вызов учитывается ровно один раз.
        DB::statement('CREATE UNIQUE INDEX delivery_attempts_request_uq ON delivery_attempts (request_id)');
        DB::statement('CREATE UNIQUE INDEX delivery_attempts_epoch_uq
            ON delivery_attempts (order_id, supplier, epoch)');

        // Ключевой индекс ловушки таймаута: пока по заказу есть НЕРАЗРЕШЁННАЯ
        // попытка, вторая открыться не может. Уход на B требует явного перевода
        // первой в sealed/abandoned — аудируемое действие, а не побочный эффект.
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_one_open_uq ON delivery_attempts (order_id)
            WHERE outcome IN ('in_flight','timeout','unknown')");

        // И физический запрет на два успеха по одному заказу.
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_one_success_uq ON delivery_attempts (order_id)
            WHERE outcome = 'succeeded'");

        // Worklist фонового разрешения неизвестных исходов.
        DB::statement("CREATE INDEX delivery_attempts_probe_idx ON delivery_attempts (next_probe_at)
            WHERE outcome IN ('in_flight','timeout','unknown')");

        Schema::create('supplier_issued_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_id', 128);
            $table->string('supplier', 8);
            $table->text('code_encrypted');
            $table->char('code_hash', 64);
            $table->char('code_last4', 4);

            // for_order — код привязан к заказу; surplus — поставщик выдал его уже
            // после того, как заказ закрыли другим кодом. Сурплус обязан быть учтён,
            // а не потерян.
            $table->string('disposition', 16)->default('unassigned');

            $table->timestampTz('received_at')->useCurrent();
        });

        DB::statement("ALTER TABLE supplier_issued_codes ADD CONSTRAINT supplier_issued_codes_disposition_chk
            CHECK (disposition IN ('unassigned','for_order','surplus'))");
        DB::statement('CREATE UNIQUE INDEX supplier_issued_codes_request_uq
            ON supplier_issued_codes (request_id)');
        DB::statement('CREATE UNIQUE INDEX supplier_issued_codes_hash_uq
            ON supplier_issued_codes (code_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_issued_codes');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('deliveries');
    }
};
