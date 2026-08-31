<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал денежных движений двойной записью.
 *
 * «Всегда сходится» здесь — не соглашение, а невозможность: несбалансированная
 * группа проводок отвергается отложенным constraint-триггером на COMMIT, а строки
 * журнала неизменяемы (CLAUDE.md §5.7).
 *
 * Счетов намеренно шесть. Возвраты, комиссии и выплаты поставщикам описаны в README
 * как «куда расти»: их присутствие в журнале без соответствующего состояния заказа
 * само порождает баги.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64);
            $table->char('currency', 3);
            $table->string('kind', 16);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_kind_chk
            CHECK (kind IN ('asset','liability','income','expense'))");
        DB::statement('CREATE UNIQUE INDEX ledger_accounts_code_currency_uq
            ON ledger_accounts (code, currency)');

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('kind', 32);

            // Идемпотентность денежной операции. Для признания оплаты ключ строится
            // по ЗАКАЗУ ('payment_captured:ord_00123'), а не по event_id.
            $table->string('idempotency_key', 160);

            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_kind_chk
            CHECK (kind IN ('payment_captured','order_delivered','payment_reversed','supplier_surplus'))");
        DB::statement('CREATE UNIQUE INDEX ledger_transactions_idempotency_uq
            ON ledger_transactions (idempotency_key)');
        DB::statement('CREATE INDEX ledger_transactions_order_idx ON ledger_transactions (order_id, kind)');

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('account_id');
            $table->string('direction', 6);

            // Только целые копейки. float в денежном пути невозможен по типу.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_chk
            CHECK (direction IN ('debit','credit'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_chk
            CHECK (amount_minor > 0)');

        // Знаковая сумма — генерируемая колонка: рассинхрон со знаком невозможен.
        DB::statement("ALTER TABLE ledger_entries ADD COLUMN amount_signed bigint
            GENERATED ALWAYS AS (CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END) STORED");

        // Повторная проводка той же пары в существующую транзакцию удвоила бы
        // остатки, оставаясь при этом сбалансированной. Индекс закрывает это.
        DB::statement('CREATE UNIQUE INDEX ledger_entries_pair_uq
            ON ledger_entries (transaction_id, account_id, direction)');
        DB::statement('CREATE INDEX ledger_entries_order_idx ON ledger_entries (order_id, account_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
