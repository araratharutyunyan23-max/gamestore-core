<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции заказа.
 *
 * Второй этап требует заказ из нескольких товаров, каждый от своего поставщика,
 * где часть может не выдаться. Первый этап зашил «один товар на заказ» прямо
 * в схему, поэтому колонки выдачи переезжают с заказа на позицию: аренда,
 * эпоха и ожидание пополнения теперь принадлежат позиции, а не заказу.
 *
 * Иначе две позиции одного заказа не смогут выдаваться параллельно — одна
 * аренда на заказ означает, что второй товар ждёт первого, и один залипший
 * поставщик держит весь заказ.
 *
 * Существующие заказы переносятся в одну позицию каждый. Это не косметика:
 * без переноса весь первый этап перестаёт работать, а его тесты обязаны
 * остаться зелёными все до одного.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');

            // Номер строки в заказе. Нужен не для красоты: он даёт устойчивый
            // порядок позиций и естественный якорь идемпотентности — повтор
            // создания заказа обязан попасть в ту же позицию, а не завести
            // вторую с тем же товаром.
            $table->smallInteger('line_no');

            // Снимок на момент покупки, как и у заказа: каталог меняется,
            // история — нет.
            $table->string('sku', 64);
            $table->bigInteger('unit_amount_minor');
            $table->char('currency', 3);

            $table->string('status', 24)->default('pending');

            // Аренда с fencing-токеном — копия механики заказа, но на позиции.
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('lease_owner', 64)->nullable();

            // Эпоха выдачи растёт ТОЛЬКО после авторитетного «не выдано».
            // После таймаута — никогда: повтор ушёл бы с новым request_id,
            // и поставщик выдал бы второй код (CLAUDE.md §5.3).
            $table->smallInteger('delivery_epoch')->default(1);
            $table->smallInteger('restock_waits')->default(0);

            // Приоритет обслуживания. Заполняется в Ш5 (лимит поставщика),
            // но колонка заводится сразу: добавлять её потом означало бы
            // переписывать индекс worklist на живой таблице.
            $table->smallInteger('priority')->default(0);

            // Worklist-колонки не nullable по той же причине, что и у заказа:
            // строка, созданная мимо эталонного пути, стала бы невидимой
            // для подметальщика.
            $table->timestampTz('status_changed_at')->useCurrent();
            $table->timestampTz('next_action_at')->useCurrent();

            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_chk CHECK (status IN (
            'pending','delivering','delivered','out_of_stock',
            'delivery_failed','refunded','cancelled'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amount_chk
            CHECK (unit_amount_minor > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_no_chk
            CHECK (line_no > 0)');

        // Одна строка на (заказ, номер строки). Повторная попытка создать
        // позицию попадёт в 23505, а не заведёт дубль.
        DB::statement('CREATE UNIQUE INDEX order_items_line_uq ON order_items (order_id, line_no)');

        DB::statement('CREATE INDEX order_items_order_idx ON order_items (order_id, line_no)');

        // Подметальщик берёт работу по позициям, а не по заказам, поэтому
        // индекс частичный: доставленные позиции в него не попадают, и он
        // не растёт вместе с историей.
        DB::statement("CREATE INDEX order_items_worklist_idx
            ON order_items (priority DESC, next_action_at, id)
            WHERE status IN ('pending','delivering','out_of_stock','delivery_failed')");

        $this->createTransitionsTable();
        $this->backfillFromOrders();
        $this->createTotalGuard();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS order_items_total_matches ON order_items');
        DB::statement('DROP TRIGGER IF EXISTS orders_total_matches_items ON orders');
        DB::statement('DROP FUNCTION IF EXISTS gs_assert_order_total_matches_items()');

        Schema::dropIfExists('order_item_status_transitions');
        Schema::dropIfExists('order_items');
    }

    /**
     * Журнал переходов позиции — зеркало заказного.
     *
     * Заводится сразу, а не в шаге про историю: переходы, не записанные в
     * момент, когда они происходили, задним числом не восстанавливаются.
     */
    private function createTransitionsTable(): void
    {
        Schema::create('order_item_status_transitions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('order_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 64)->nullable();
            $table->string('actor', 32)->default('system');
            $table->string('trace_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX order_item_transitions_item_idx
            ON order_item_status_transitions (order_item_id, id)');
        DB::statement('CREATE INDEX order_item_transitions_order_idx
            ON order_item_status_transitions (order_id, created_at)');
    }

    /**
     * Каждый существующий заказ становится заказом из одной позиции.
     *
     * Порядок важен: перенос идёт ДО создания сторожевого триггера, иначе
     * миграция упала бы на собственных данных — в момент создания триггера
     * ни у одного заказа позиций ещё нет.
     */
    private function backfillFromOrders(): void
    {
        DB::statement("
            INSERT INTO order_items
                (order_id, product_id, line_no, sku, unit_amount_minor, currency, status,
                 delivery_epoch, restock_waits, status_changed_at, next_action_at,
                 delivered_at, failed_at, created_at, updated_at)
            SELECT
                o.id, o.product_id, 1, o.sku, o.amount_minor, o.currency,
                CASE o.status
                    WHEN 'delivered' THEN 'delivered'
                    WHEN 'delivering' THEN 'delivering'
                    WHEN 'out_of_stock' THEN 'out_of_stock'
                    WHEN 'delivery_failed' THEN 'delivery_failed'
                    WHEN 'cancelled' THEN 'cancelled'
                    ELSE 'pending'
                END,
                o.delivery_epoch, o.restock_waits, o.status_changed_at, o.next_action_at,
                o.delivered_at, o.failed_at, o.created_at, o.updated_at
            FROM orders o
        ");
    }

    /**
     * Итог заказа обязан равняться сумме позиций.
     *
     * Проверка ОТЛОЖЕННАЯ, и это принципиально: позиции вставляются несколькими
     * строками, и в середине операции сумма закономерно не сходится. Немедленная
     * проверка запретила бы создавать заказ из двух товаров вообще — ровно та же
     * логика, что у триггера баланса журнала.
     *
     * Триггер висит на обеих таблицах: только на позициях он пропустил бы заказ
     * вовсе без позиций, а это и есть самый дорогой случай — заказ, за который
     * заплатили, и в котором нечего выдавать.
     */
    private function createTotalGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_assert_order_total_matches_items() RETURNS trigger AS $$
            DECLARE
                target_order bigint;
                order_total bigint;
                items_total bigint;
            BEGIN
                -- Развёрнуто через IF, а не одним CASE: plpgsql вычисляет
                -- выражение CASE целиком, поэтому ветка с NEW.order_id падала
                -- бы на таблице orders, где такого поля нет. И NEW существует
                -- не всегда — при DELETE запись только одна, OLD.
                IF TG_TABLE_NAME = 'orders' THEN
                    IF TG_OP = 'DELETE' THEN
                        target_order := OLD.id;
                    ELSE
                        target_order := NEW.id;
                    END IF;
                ELSE
                    IF TG_OP = 'DELETE' THEN
                        target_order := OLD.order_id;
                    ELSE
                        target_order := NEW.order_id;
                    END IF;
                END IF;

                -- Заказ мог быть удалён в этой же транзакции: тогда проверять
                -- нечего, и падать не на чем.
                SELECT amount_minor INTO order_total FROM orders WHERE id = target_order;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(unit_amount_minor), 0) INTO items_total
                  FROM order_items WHERE order_id = target_order;

                IF items_total <> order_total THEN
                    RAISE EXCEPTION
                        'order % total is % but its items sum to %',
                        target_order, order_total, items_total;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER order_items_total_matches
                AFTER INSERT OR UPDATE OR DELETE ON order_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION gs_assert_order_total_matches_items();

            -- UPDATE OF amount_minor, а не UPDATE: статус и аренда заказа
            -- меняются на каждом шаге выдачи, и проверять сумму позиций на
            -- каждом захвате аренды означало бы платить двумя выборками за
            -- операцию, которая к сумме отношения не имеет.
            CREATE CONSTRAINT TRIGGER orders_total_matches_items
                AFTER INSERT OR UPDATE OF amount_minor ON orders
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION gs_assert_order_total_matches_items();
        SQL);
    }
};
