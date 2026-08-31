<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->enforceModelStrictness();
        $this->forbidDestructiveCommandsOutsideLocal();
    }

    /**
     * N+1 в этом проекте — не «медленно», а падение теста (CLAUDE.md §4).
     * Ленивая загрузка вне продакшена бросает исключение, поэтому забытый with()
     * виден на первом же прогоне, а не на витрине под нагрузкой.
     */
    private function enforceModelStrictness(): void
    {
        $strict = ! $this->app->environment('production');

        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);
        Model::preventAccessingMissingAttributes($strict);

        // Модели — только отображение таблицы: никаких массовых присвоений «на всё подряд».
        Model::unguard(false);
    }

    private function forbidDestructiveCommandsOutsideLocal(): void
    {
        DB::prohibitDestructiveCommands($this->app->environment('production'));
    }
}
