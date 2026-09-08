<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Журналы переходов — только на добавление.
 *
 * ТЗ 4.2: «история только дополняется, задним числом ничего не
 * переписывается». Для журнала проводок это было верно с первого этапа,
 * а для журналов переходов — нет: неизменяемость держал один триггер, и
 * висел он только на `ledger_entries`.
 *
 * Разница не теоретическая. Восстановление состояния заказа на момент
 * собирается ИЗ ЭТИХ таблиц, и правка одной строки в них меняет прошлое —
 * причём меняет молча, потому что проверить его больше нечем.
 *
 * Найдено при планировании второго этапа: план сначала утверждал, что
 * append-only уже есть, и это оказалось неправдой. Здесь долг закрывается.
 *
 * Запрещаются UPDATE и DELETE — то есть переписывание. Вставка строки
 * с прошедшей отметкой времени не запрещается: миграция данных и перенос
 * истории делают именно это, а отличить их от подлога на уровне триггера
 * нельзя. Граница названа прямо, чтобы её не приняли за большее.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const JOURNALS = ['order_status_transitions', 'order_item_status_transitions'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gs_forbid_history_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only (attempted %)', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = '23514';
            END $$;
        SQL);

        foreach (self::JOURNALS as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION gs_forbid_history_mutation()");
        }

        // Чтение истории на момент идёт по времени, а не по идентификатору:
        // без индекса восстановление состояния на дату превращается в
        // последовательный проход по всей истории.
        DB::statement('CREATE INDEX IF NOT EXISTS order_status_transitions_as_of_idx
            ON order_status_transitions (order_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS order_item_transitions_as_of_idx
            ON order_item_status_transitions (order_item_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS ledger_entries_as_of_idx
            ON ledger_entries (created_at, account_id)');
    }

    public function down(): void
    {
        foreach (self::JOURNALS as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");
        }

        DB::statement('DROP FUNCTION IF EXISTS gs_forbid_history_mutation()');
        DB::statement('DROP INDEX IF EXISTS order_status_transitions_as_of_idx');
        DB::statement('DROP INDEX IF EXISTS order_item_transitions_as_of_idx');
        DB::statement('DROP INDEX IF EXISTS ledger_entries_as_of_idx');
    }
};
