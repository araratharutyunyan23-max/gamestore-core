<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал прогонов сверки.
 *
 * Нужен ради одного вопроса, на который иначе нечем ответить: «сверка вообще
 * работает?». Отсутствие находок означает либо здоровые данные, либо
 * остановившийся шедулер, и эти два случая снаружи неразличимы — а различать
 * их надо, потому что во втором система молча слепнет.
 *
 * Хранить отметку в кеше было бы дешевле, но кеш переживает не всякий
 * перезапуск, и «сверка не запускалась» превратилось бы в ложную тревогу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->boolean('full')->default(false);
            $table->unsignedInteger('anomalies_count')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->timestampTz('finished_at')->useCurrent();
        });

        // Читается всегда одинаково: последняя строка. DESC-индекс, чтобы это
        // был один Index Scan, а не сортировка всей истории прогонов.
        DB::statement('CREATE INDEX reconciliation_runs_recent_idx ON reconciliation_runs (finished_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
