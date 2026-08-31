<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Инварианты, вынесенные в БД.
 *
 * Здесь лежит то, что не должно зависеть от аккуратности кода: нарушить эти
 * правила нельзя ни из сервиса, ни из джобы, ни из psql, ни из будущей миграции,
 * которая про них забудет.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->forbidFinalStatusDowngrade();
        $this->ensureStockRowExists();
        $this->syncInStockFlag();
        $this->forbidLicenseKeyReassignment();
        $this->makeLedgerAppendOnly();
        $this->assertLedgerBalanced();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_balanced      ON ledger_entries;
            DROP TRIGGER IF EXISTS ledger_entries_immutable     ON ledger_entries;
            DROP TRIGGER IF EXISTS license_keys_no_reassign     ON license_keys;
            DROP TRIGGER IF EXISTS product_stock_flag_ins       ON product_stock;
            DROP TRIGGER IF EXISTS product_stock_flag_upd       ON product_stock;
            DROP TRIGGER IF EXISTS products_ensure_stock_row    ON products;
            DROP TRIGGER IF EXISTS orders_no_final_downgrade    ON orders;

            DROP FUNCTION IF EXISTS gs_assert_ledger_balanced();
            DROP FUNCTION IF EXISTS gs_forbid_ledger_mutation();
            DROP FUNCTION IF EXISTS gs_forbid_key_reassignment();
            DROP FUNCTION IF EXISTS gs_sync_in_stock();
            DROP FUNCTION IF EXISTS gs_ensure_stock_row();
            DROP FUNCTION IF EXISTS gs_forbid_final_downgrade();
        SQL);
    }

    /**
     * Из финального статуса выхода нет. Это защита от главной ошибки восстановления:
     * проигравший гонку воркер, поймавший 23505, не имеет права откатить уже
     * доставленный заказ в delivery_failed.
     */
    private function forbidFinalStatusDowngrade(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_forbid_final_downgrade() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('delivered','payment_failed','cancelled')
                   AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'order % is final (%), refusing transition to %',
                        OLD.public_id, OLD.status, NEW.status
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER orders_no_final_downgrade
                BEFORE UPDATE OF status ON orders
                FOR EACH ROW EXECUTE FUNCTION gs_forbid_final_downgrade();
        SQL);
    }

    /**
     * Строка остатка обязана существовать для каждого товара: иначе UPDATE счётчика
     * молча задевает ноль строк, ключ уходит, а витрина показывает фикцию.
     */
    private function ensureStockRowExists(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_ensure_stock_row() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                INSERT INTO public.product_stock (product_id) VALUES (NEW.id)
                ON CONFLICT (product_id) DO NOTHING;

                -- У supplier-товара локального остатка нет: доступность выясняется
                -- только вызовом поставщика. Он виден на витрине, пока активен, а
                -- реальное отсутствие превращается в восстановимый out_of_stock уже
                -- на выдаче. Без этого половина каталога навсегда исчезла бы
                -- с витрины, потому что счётчик у неё всегда 0.
                IF NEW.supply_mode = 'supplier' THEN
                    UPDATE public.products SET in_stock = NEW.is_active WHERE id = NEW.id;
                END IF;

                RETURN NULL;
            END $$;

            CREATE TRIGGER products_ensure_stock_row
                AFTER INSERT ON products
                FOR EACH ROW EXECUTE FUNCTION gs_ensure_stock_row();
        SQL);
    }

    /**
     * Флаг in_stock — денормализация ради витрины. Два триггера, а не один:
     * WHEN с OLD на INSERT недопустим, а без INSERT-ветки товар, созданный сразу
     * с остатком, никогда бы не появился на витрине.
     */
    private function syncInStockFlag(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_sync_in_stock() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
            BEGIN
                IF (NEW.available_count > 0) IS DISTINCT FROM (COALESCE(OLD.available_count, 0) > 0) THEN
                    -- Только для pool: у supplier-товара счётчик всегда 0, и этот
                    -- триггер иначе снимал бы его с витрины при каждом касании.
                    UPDATE public.products
                       SET in_stock = (NEW.available_count > 0), updated_at = now()
                     WHERE id = NEW.product_id
                       AND supply_mode = 'pool'
                       AND in_stock IS DISTINCT FROM (NEW.available_count > 0);
                END IF;
                RETURN NULL;
            END $$;

            CREATE TRIGGER product_stock_flag_ins
                AFTER INSERT ON product_stock
                FOR EACH ROW EXECUTE FUNCTION gs_sync_in_stock();

            CREATE TRIGGER product_stock_flag_upd
                AFTER UPDATE OF available_count ON product_stock
                FOR EACH ROW WHEN (OLD.available_count IS DISTINCT FROM NEW.available_count)
                EXECUTE FUNCTION gs_sync_in_stock();
        SQL);
    }

    /**
     * Выданный ключ нельзя переназначить на другую доставку. Уникальный индекс
     * защищает от двух ключей на одну выдачу; этот триггер — от обратного.
     */
    private function forbidLicenseKeyReassignment(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_forbid_key_reassignment() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'issued' AND NEW.delivery_id IS DISTINCT FROM OLD.delivery_id THEN
                    RAISE EXCEPTION 'license key % is already issued to delivery %',
                        OLD.id, OLD.delivery_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER license_keys_no_reassign
                BEFORE UPDATE ON license_keys
                FOR EACH ROW EXECUTE FUNCTION gs_forbid_key_reassignment();
        SQL);
    }

    /** Журнал — только на добавление. Правка истории денег невозможна. */
    private function makeLedgerAppendOnly(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_forbid_ledger_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only (attempted %)', TG_OP
                    USING ERRCODE = '23514';
            END $$;

            CREATE TRIGGER ledger_entries_immutable
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION gs_forbid_ledger_mutation();
        SQL);
    }

    /**
     * «Журнал, который всегда сходится» — как невозможность, а не как соглашение.
     *
     * Проверка отложенная: внутри транзакции строки появляются по одной, и
     * немедленный контроль отверг бы первую же. На COMMIT сумма по каждой
     * затронутой транзакции обязана быть нулём, а проводок — не меньше двух.
     */
    private function assertLedgerBalanced(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_assert_ledger_balanced() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_sum   bigint;
                v_count integer;
            BEGIN
                SELECT COALESCE(SUM(amount_signed), 0), COUNT(*)
                  INTO v_sum, v_count
                  FROM ledger_entries
                 WHERE transaction_id = NEW.transaction_id;

                IF v_count < 2 THEN
                    RAISE EXCEPTION 'ledger transaction % has % entries, double entry requires at least 2',
                        NEW.transaction_id, v_count
                        USING ERRCODE = '23514';
                END IF;

                IF v_sum <> 0 THEN
                    RAISE EXCEPTION 'ledger transaction % is unbalanced by % minor units',
                        NEW.transaction_id, v_sum
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION gs_assert_ledger_balanced();
        SQL);
    }
};
