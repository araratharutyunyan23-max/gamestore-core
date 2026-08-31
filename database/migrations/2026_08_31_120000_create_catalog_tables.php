<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог: товары, денормализованный остаток и пул ключей.
 *
 * Списки CHECK-ограничений записаны текстом намеренно и не интерполируются из
 * PHP-энумов: иначе добавление кейса задним числом изменило бы старую миграцию,
 * и `migrate:fresh` в CI дал бы схему, отличную от прода. Согласованность
 * проверяет тест-страж (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Внешний идентификатор из контракта. Внутренний PK — bigint, чтобы
            // текстовый ключ не протекал в ширину каждого вторичного индекса.
            $table->string('sku', 64)->unique('products_sku_uq');
            $table->string('name', 255);
            $table->string('type', 32);
            $table->bigInteger('price_minor');
            $table->char('currency', 3);

            // pool     — выдаём ключ из собственного пула
            // supplier — идём во внешнего поставщика (этап 3)
            $table->string('supply_mode', 16)->default('pool');

            $table->boolean('is_active')->default(true);

            // Денормализация ради витрины: попадает в предикат покрывающего индекса,
            // поэтому читается без обращения к product_stock (docs/PLAN.md, Ш6).
            $table->boolean('in_stock')->default(false);

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_chk
            CHECK (type IN ('topup','key','subscription','giftcard'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_supply_mode_chk
            CHECK (supply_mode IN ('pool','supplier'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_chk
            CHECK (price_minor > 0)');

        Schema::create('product_stock', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->primary();
            $table->integer('available_count')->default(0);
            $table->integer('reserved_count')->default(0);
            $table->integer('issued_count')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        // CHECK остаётся ассертом целостности, но декремент выполняется через
        // GREATEST(...,0) и не имеет права уронить продажу: занижённый счётчик
        // после импорта ключей иначе валит восстановление (CLAUDE.md §5.6).
        DB::statement('ALTER TABLE product_stock ADD CONSTRAINT product_stock_counts_chk
            CHECK (available_count >= 0 AND reserved_count >= 0 AND issued_count >= 0)');

        Schema::create('license_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');

            // Сам код зашифрован, а уникальность держится на детерминированном
            // хэше: шифртекст недетерминирован и уникальным индексом не защищается.
            $table->text('code_encrypted');
            $table->char('code_hash', 64);
            $table->char('code_last4', 4);

            $table->string('status', 16)->default('available');
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE license_keys ADD CONSTRAINT license_keys_status_chk
            CHECK (status IN ('available','reserved','issued'))");

        // «Один ключ не может уйти в два заказа» — вот индекс, который это обеспечивает.
        DB::statement('CREATE UNIQUE INDEX license_keys_code_hash_uq ON license_keys (code_hash)');
        DB::statement('CREATE UNIQUE INDEX license_keys_delivery_uq  ON license_keys (delivery_id)
            WHERE delivery_id IS NOT NULL');

        // Worklist захвата ключа: индекс покрывает ровно свободные строки,
        // поэтому не растёт вместе с историей выдач.
        DB::statement("CREATE INDEX license_keys_available_idx ON license_keys (product_id, id)
            WHERE status = 'available'");

        // Возврат протухших резервов после падения воркера.
        DB::statement("CREATE INDEX license_keys_expired_reservations_idx ON license_keys (reserved_until)
            WHERE status = 'reserved'");

        // Горячий запрос витрины: Index Only Scan без обращения к куче.
        DB::statement('CREATE INDEX products_showcase_cov_idx
            ON products (type, price_minor, id) INCLUDE (sku, name, currency)
            WHERE is_active AND in_stock');
    }

    public function down(): void
    {
        Schema::dropIfExists('license_keys');
        Schema::dropIfExists('product_stock');
        Schema::dropIfExists('products');
    }
};
