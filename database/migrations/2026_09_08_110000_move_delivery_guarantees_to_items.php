<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Гарантии выдачи переезжают с заказа на позицию.
 *
 * Это самая опасная правка второго этапа. Индекс `deliveries_order_uq` —
 * «одна выдача на заказ» — был центральным инвариантом первого этапа: именно
 * он, а не код, запрещал выдать товар дважды. Заказ из нескольких товаров
 * делает его неверным: три позиции обязаны получить три кода.
 *
 * Заменяющий инвариант ровно такой же силы — «одна выдача на ПОЗИЦИЮ». Он не
 * слабее: множество, по которому запрещено дублирование, стало мельче, но
 * запрет остался физическим.
 *
 * Состязательный тест на новый инвариант написан ДО этой миграции и падал на
 * старой схеме. Порядок не случайный: между двумя состояниями схемы есть
 * промежуток, где «ровно одна выдача» не обеспечено ничем, и заметить это
 * можно только тестом, который уже стоит на месте.
 *
 * То же самое с попытками обращения к поставщику: «одна открытая попытка» и
 * «один успех» были заданы на заказ. У многопозиционного заказа это означало
 * бы, что вторая позиция не может даже начать выдачу, пока не закончила первая.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addItemColumn('deliveries');
        $this->addItemColumn('delivery_attempts');

        $this->backfill('deliveries');
        $this->backfill('delivery_attempts');

        // NOT NULL ставится ПОСЛЕ переноса: на пустой колонке ограничение
        // не прошло бы, а на заполненной оно и есть доказательство переноса.
        DB::statement('ALTER TABLE deliveries ALTER COLUMN order_item_id SET NOT NULL');
        DB::statement('ALTER TABLE delivery_attempts ALTER COLUMN order_item_id SET NOT NULL');

        $this->moveDeliveryGuarantee();
        $this->moveAttemptGuarantees();
        $this->dropDeadOrderColumns();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->smallInteger('delivery_epoch')->default(1);
        });

        DB::statement('DROP INDEX IF EXISTS deliveries_order_item_uq');
        DB::statement('CREATE UNIQUE INDEX deliveries_order_uq ON deliveries (order_id)');

        DB::statement('DROP INDEX IF EXISTS delivery_attempts_item_epoch_uq');
        DB::statement('DROP INDEX IF EXISTS delivery_attempts_item_open_uq');
        DB::statement('DROP INDEX IF EXISTS delivery_attempts_item_success_uq');

        DB::statement('CREATE UNIQUE INDEX delivery_attempts_epoch_uq
            ON delivery_attempts (order_id, supplier, epoch)');
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_one_open_uq ON delivery_attempts (order_id)
            WHERE outcome IN ('in_flight','timeout','unknown')");
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_one_success_uq ON delivery_attempts (order_id)
            WHERE outcome = 'succeeded'");

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('order_item_id');
        });
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropColumn('order_item_id');
        });
    }

    /**
     * Аренда и эпоха выдачи уехали на позицию — на заказе их держать больше
     * незачем.
     *
     * Колонки убираются, а не оставляются «на всякий случай». Оставленные,
     * они врали бы: `delivery_epoch` навсегда застыл бы на единице, а
     * `lease_owner` — на NULL, и первый же человек, заглянувший в базу
     * при разборе инцидента, сделал бы неверный вывод. Мёртвое поле, похожее
     * на живое, хуже отсутствующего.
     *
     * `orders.product_id` и `orders.sku` при этом ОСТАЮТСЯ: они снимок первой
     * позиции, на них опирается код первого этапа, и убирать их надо отдельным
     * шагом, когда этот код уйдёт.
     */
    private function dropDeadOrderColumns(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_worklist_idx');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['lease_token', 'lease_owner', 'lease_expires_at', 'delivery_epoch']);
        });

        // Индекс пересоздаётся без условия по аренде: подметальщик теперь
        // спрашивает про аренду позиций отдельным NOT EXISTS.
        DB::statement("CREATE INDEX orders_worklist_idx ON orders (next_action_at, id)
            WHERE status IN ('paid','delivering','out_of_stock','delivery_failed')");
    }

    private function addItemColumn(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('order_item_id')->nullable()->after('order_id');
            $blueprint->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });
    }

    /**
     * Существующие строки принадлежат единственной позиции своего заказа.
     *
     * Однозначно: до этого этапа заказ был одного товара, и позиция у него
     * ровно одна — её создала предыдущая миграция.
     */
    private function backfill(string $table): void
    {
        DB::statement("
            UPDATE {$table} t
               SET order_item_id = i.id
              FROM order_items i
             WHERE i.order_id = t.order_id
               AND i.line_no = 1
               AND t.order_item_id IS NULL
        ");
    }

    private function moveDeliveryGuarantee(): void
    {
        // Новый индекс создаётся ДО удаления старого: в промежутке действуют
        // оба, и ни одной секунды заказ не остаётся без защиты от двойной
        // выдачи. Обратный порядок открыл бы окно, в котором её нет.
        DB::statement('CREATE UNIQUE INDEX deliveries_order_item_uq ON deliveries (order_item_id)');
        DB::statement('DROP INDEX IF EXISTS deliveries_order_uq');

        // Индекс по заказу остаётся, но уже НЕ уникальный: выдачи заказа
        // читаются вместе, и без него это seq scan.
        DB::statement('CREATE INDEX deliveries_order_idx ON deliveries (order_id)');
    }

    private function moveAttemptGuarantees(): void
    {
        DB::statement('CREATE UNIQUE INDEX delivery_attempts_item_epoch_uq
            ON delivery_attempts (order_item_id, supplier, epoch)');

        // Ключевой индекс ловушки таймаута, теперь на позиции: пока по позиции
        // есть НЕРАЗРЕШЁННАЯ попытка, вторая открыться не может. Уход ко второму
        // поставщику требует явного перевода первой в sealed/abandoned.
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_item_open_uq
            ON delivery_attempts (order_item_id)
            WHERE outcome IN ('in_flight','timeout','unknown')");

        // И физический запрет на два успеха по одной позиции.
        DB::statement("CREATE UNIQUE INDEX delivery_attempts_item_success_uq
            ON delivery_attempts (order_item_id)
            WHERE outcome = 'succeeded'");

        DB::statement('DROP INDEX IF EXISTS delivery_attempts_epoch_uq');
        DB::statement('DROP INDEX IF EXISTS delivery_attempts_one_open_uq');
        DB::statement('DROP INDEX IF EXISTS delivery_attempts_one_success_uq');
    }
};
