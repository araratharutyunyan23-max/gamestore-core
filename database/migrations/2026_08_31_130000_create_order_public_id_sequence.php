<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Последовательность для внешнего идентификатора заказа (ord_00123 из контракта).
 *
 * Номер выдаёт БД, а не PHP: два параллельных процесса иначе получили бы
 * одно и то же значение, и второй заказ упал бы на orders_public_id_uq —
 * то есть обычная одновременная покупка выглядела бы как сбой.
 *
 * nextval работает вне транзакции и не откатывается, поэтому в нумерации
 * возможны пропуски. Это осознанно: пропуск номера безвреден, а повтор —
 * нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS orders_public_id_seq AS bigint START WITH 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS orders_public_id_seq');
    }
};
