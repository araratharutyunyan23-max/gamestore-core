<?php

declare(strict_types=1);

namespace App\Domain\Ops\Actions;

use App\Domain\Ops\DTO\HealthReport;
use App\Domain\Ops\Repositories\OpsRepository;
use App\Support\Cfg;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Проверка живости зависимостей.
 *
 * Проверяется не «настроено ли соединение», а «отвечает ли оно прямо сейчас»:
 * конфигурация бывает верной у выключенного сервера, и health, читающий
 * конфиг, зелен ровно тогда, когда он бесполезен.
 */
final readonly class CheckHealth
{
    public function __construct(
        private OpsRepository $ops,
        private CacheRepository $cache,
    ) {}

    public function execute(): HealthReport
    {
        $databaseUp = $this->ops->databaseIsReachable();

        return new HealthReport(
            database: $databaseUp,
            cache: $this->cacheIsReachable(),
            // Возраст сверки читается из базы, поэтому спрашивать его при
            // мёртвой базе бессмысленно — получим не «сверка стоит», а ошибку.
            reconciliationAgeSeconds: $databaseUp ? $this->ops->lastReconciliationAgeSeconds() : null,
            reconciliationMaxAgeSeconds: Cfg::reconciliationMaxAgeSeconds(),
        );
    }

    private function cacheIsReachable(): bool
    {
        try {
            // Запись, а не чтение: read-only реплика и переполненная память
            // отвечают на чтение и молча теряют запись, а очередь и аренды
            // выдачи опираются именно на запись.
            $this->cache->put('ops:health:probe', 1, 10);

            return $this->cache->get('ops:health:probe') !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
