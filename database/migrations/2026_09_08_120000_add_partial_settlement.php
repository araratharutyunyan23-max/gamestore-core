<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Частичный расчёт по заказу: что смогли выдать — выдано, за остальное деньги
 * возвращены (ТЗ 1.2 и 1.3).
 *
 * Появляются два КОНЕЧНЫХ статуса заказа. Раньше их не было и быть не могло:
 * товар в заказе был один, и «частично» не существовало как состояние.
 * Шаг Ш2 обнажил дыру — заказ, где часть выдана, а часть закрыта, получал
 * статус `delivery_failed`, который НЕ финален, и подметальщик тянул бы такой
 * заказ вечно, хотя делать с ним уже нечего.
 *
 * `partially_delivered` — часть товара у покупателя, за остальное деньги
 * возвращены. Это честное завершение, а не авария.
 * `refunded` — не выдано ничего, возвращено всё.
 *
 * `delivery_failed` остаётся и остаётся НЕфинальным: это «стоит и ждёт
 * повтора», а не «закончено». Разница принципиальная — из первого есть выход
 * назад в выдачу, из вторых нет.
 *
 * Плюс счёт `refunds_payable`: возврат не уничтожает обязательство, а меняет
 * его природу. Мы больше не должны покупателю товар — мы должны ему деньги,
 * и пока они не отправлены, долг обязан быть виден в журнале.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_chk');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_chk CHECK (status IN (
            'created','paid','delivering','delivered','payment_failed',
            'out_of_stock','delivery_failed','partially_delivered','refunded','cancelled'))");

        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_kind_chk');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_kind_chk
            CHECK (kind IN ('payment_captured','order_delivered','payment_reversed',
                            'supplier_surplus','item_refunded'))");

        DB::statement("INSERT INTO ledger_accounts (code, kind, currency)
            VALUES ('refunds_payable', 'liability', 'RUB')
            ON CONFLICT (code, currency) DO NOTHING");

        $this->createSettlementGuard();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS orders_settled_when_final ON orders');
        DB::statement('DROP FUNCTION IF EXISTS gs_assert_order_settled()');

        DB::statement("DELETE FROM ledger_accounts WHERE code = 'refunds_payable'");

        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_kind_chk');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_kind_chk
            CHECK (kind IN ('payment_captured','order_delivered','payment_reversed','supplier_surplus'))");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_chk');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_chk CHECK (status IN (
            'created','paid','delivering','delivered','payment_failed',
            'out_of_stock','delivery_failed','cancelled'))");
    }

    /**
     * «Оплачено = выдано + возвращено» — записанное так, что проверяет база.
     *
     * Формулировка ТЗ в терминах журнала звучит короче: у заказа, дошедшего до
     * конечного состояния, остаток по `customer_prepayment` равен нулю. Пока
     * заказ идёт, остаток законно ненулевой — деньги получены, товар ещё нет.
     * Обнулить его может только выдача (в выручку) или возврат (в долг перед
     * покупателем). Третьего способа нет, и именно это здесь и утверждается.
     *
     * Проверка ОТЛОЖЕННАЯ: заказ становится финальным одним оператором, а
     * проводки по последней позиции могли лечь в этой же транзакции. Немедленная
     * проверка увидела бы половину картины.
     *
     * Проверяются только заказы, по которым деньги вообще двигались. Отменённый
     * до оплаты заказ ничего не должен и обнулять ему нечего.
     */
    private function createSettlementGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_assert_order_settled() RETURNS trigger AS $$
            DECLARE
                open_prepayment bigint;
            BEGIN
                SELECT COALESCE(SUM(
                           CASE WHEN e.direction = 'credit'
                                THEN e.amount_minor
                                ELSE -e.amount_minor
                           END), 0)
                  INTO open_prepayment
                  FROM ledger_entries e
                  JOIN ledger_accounts a ON a.id = e.account_id
                 WHERE e.order_id = NEW.id
                   AND a.code = 'customer_prepayment';

                IF open_prepayment <> 0 THEN
                    RAISE EXCEPTION
                        'order % is final (%) but % minor units of prepayment are still open',
                        NEW.id, NEW.status, open_prepayment;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER orders_settled_when_final
                AFTER UPDATE OF status ON orders
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (NEW.status IN ('delivered', 'partially_delivered', 'refunded'))
                EXECUTE FUNCTION gs_assert_order_settled();
        SQL);
    }
};
