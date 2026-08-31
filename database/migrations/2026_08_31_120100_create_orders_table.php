<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заказ. По условию задания заказ создаётся ПО SKU, поэтому одна позиция и
 * quantity = 1 (CLAUDE.md §10.1). Это убирает целый класс ошибок в журнале
 * (проводка суммы заказа вместо суммы позиции) и не теряет ни одного критерия
 * приёмки; расширение до мультипозиции описано в README.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Внешний идентификатор формата ord_00123 из контракта вебхука.
            $table->string('public_id', 32)->unique('orders_public_id_uq');

            // Обязательный заголовок Idempotency-Key: защищает от повторного
            // создания заказа при ретрае клиента.
            $table->string('idempotency_key', 128)->unique('orders_idempotency_key_uq');

            $table->unsignedBigInteger('product_id');

            // Снимок цены и SKU на момент покупки: каталог меняется, история — нет.
            $table->string('sku', 64);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('status', 24)->default('created');

            // Аренда с fencing-токеном. Взаимное исключение выдачи строится на ней,
            // а не на CAS по статусу: из delivering -> delivering CAS вернёт false
            // и второго воркера не остановит (CLAUDE.md §5.1).
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('lease_owner', 64)->nullable();

            // Эпоха выдачи. Растёт ТОЛЬКО после авторитетного «не выдано» и никогда
            // после таймаута — иначе повтор уйдёт с новым request_id и поставщик
            // выдаст второй код (CLAUDE.md §5.3).
            $table->smallInteger('delivery_epoch')->default(1);
            $table->smallInteger('restock_waits')->default(0);

            // Worklist-колонки не nullable: строка, созданная мимо эталонного пути,
            // иначе стала бы невидимой для подметальщика.
            $table->timestampTz('status_changed_at')->useCurrent();
            $table->timestampTz('next_action_at')->useCurrent();

            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 64)->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_chk CHECK (status IN (
            'created','paid','delivering','delivered','payment_failed',
            'out_of_stock','delivery_failed','cancelled'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_chk CHECK (amount_minor > 0)');

        // Подметальщик работает только по невыполненным заказам, поэтому индекс
        // не растёт вместе с историей доставленных.
        DB::statement("CREATE INDEX orders_worklist_idx ON orders (next_action_at, id)
            WHERE status IN ('paid','delivering','out_of_stock','delivery_failed')");

        DB::statement('CREATE INDEX orders_needs_review_idx ON orders (status_changed_at)
            WHERE needs_review');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
