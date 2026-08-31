<?php

declare(strict_types=1);

namespace App\Domain\Ops\DTO;

final readonly class HealthReport
{
    public function __construct(
        public bool $database,
        public bool $cache,
        public ?int $reconciliationAgeSeconds,
        public int $reconciliationMaxAgeSeconds,
    ) {}

    /**
     * Возраст сверки входит в здоровье наравне с базой.
     *
     * Остановившаяся сверка не роняет торговлю — и именно поэтому опасна:
     * заказы продолжают идти, расхождения продолжают копиться, а сигнала нет.
     * Отсутствие прогонов вовсе считается нездоровьем, а не «пока рано»:
     * шедулер обязан был отработать в первую же минуту.
     */
    public function reconciliationIsFresh(): bool
    {
        return $this->reconciliationAgeSeconds !== null
            && $this->reconciliationAgeSeconds <= $this->reconciliationMaxAgeSeconds;
    }

    public function isHealthy(): bool
    {
        return $this->database && $this->cache && $this->reconciliationIsFresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isHealthy() ? 'ok' : 'degraded',
            'checks' => [
                'database' => $this->database ? 'ok' : 'down',
                'cache' => $this->cache ? 'ok' : 'down',
                'reconciliation' => [
                    'status' => $this->reconciliationIsFresh() ? 'ok' : 'stale',
                    'age_seconds' => $this->reconciliationAgeSeconds,
                    'max_age_seconds' => $this->reconciliationMaxAgeSeconds,
                ],
            ],
        ];
    }
}
