<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Cfg;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Расписание — часть контракта, а не деталь запуска.
 *
 * Повод: быстрой сверки в расписании не было вовсе. Пять детекторов денежных
 * расхождений срабатывали только по ручному запросу эндпоинта или раз в сутки
 * ночью, а `/health`, который меряет свежесть сверки, из-за этого был красным
 * до трёх утра. Оба факта существовали одновременно и не противоречили ни
 * одному тесту, потому что расписание никто не проверял.
 */
final class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_quick_reconciliation_runs_often_enough_to_keep_health_green(): void
    {
        $event = $this->scheduled('shop:reconcile', full: false);

        self::assertNotNull($event, 'Быстрая сверка не стоит в расписании.');

        $interval = $this->intervalSeconds($event);

        // Связь двух чисел, которые обязаны согласовываться: порог свежести
        // в /health и частота прогонов. Разъехавшись, они дают либо вечно
        // красный health при исправной системе, либо зелёный при остановившейся
        // сверке. Второе хуже.
        self::assertLessThan(
            Cfg::reconciliationMaxAgeSeconds(),
            $interval,
            sprintf(
                'Сверка идёт раз в %d с, а /health краснеет через %d с — health будет врать.',
                $interval,
                Cfg::reconciliationMaxAgeSeconds(),
            ),
        );
    }

    #[Test]
    public function the_expensive_full_reconciliation_stays_nightly(): void
    {
        $event = $this->scheduled('shop:reconcile', full: true);

        self::assertNotNull($event, 'Полная сверка не стоит в расписании.');

        // Три её проверки идут по всей истории. Ежеминутно они не нужны, и
        // поставить их туда означало бы сложность, растущую с объёмом данных.
        self::assertGreaterThanOrEqual(3600, $this->intervalSeconds($event));
    }

    #[Test]
    public function the_recovery_commands_are_scheduled_without_overlapping(): void
    {
        foreach (['payments:drain-unapplied', 'delivery:resolve-unknown', 'orders:sweep-stuck'] as $command) {
            $event = $this->scheduled($command);

            self::assertNotNull($event, "Команда восстановления {$command} не стоит в расписании.");

            // Два одновременных прохода поставили бы каждое событие дважды.
            // Джобы идемпотентны, но лишняя работа под нагрузкой не нужна.
            self::assertTrue($event->withoutOverlapping, "{$command} может наложиться сам на себя.");
        }
    }

    private function scheduled(string $command, ?bool $full = null): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (! str_contains($event->command ?? '', $command)) {
                continue;
            }

            $hasFull = str_contains($event->command ?? '', '--full');

            if ($full === null || $hasFull === $full) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Промежуток между двумя ближайшими запусками — по самому cron-выражению,
     * а не по тому, каким методом его записали в коде.
     */
    private function intervalSeconds(Event $event): int
    {
        $cron = new CronExpression($event->expression);
        $first = $cron->getNextRunDate('now');
        $second = $cron->getNextRunDate($first);

        return $second->getTimestamp() - $first->getTimestamp();
    }
}
