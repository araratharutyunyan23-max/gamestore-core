<?php

declare(strict_types=1);

namespace Tests\Race;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * База для состязательных сценариев.
 *
 * Тест поднимает СВОЙ экземпляр приложения отдельным процессом и бьёт по нему
 * настоящим HTTP. Три причины, почему иначе нельзя:
 *
 * 1. RefreshDatabase оборачивает тест в транзакцию — параллельные соединения
 *    не увидели бы фикстур, и гонки просто не существовало бы. Отсюда
 *    DatabaseTruncation.
 * 2. Встроенный сервер PHP без PHP_CLI_SERVER_WORKERS однопоточный: пятьдесят
 *    «параллельных» запросов выстроились бы в очередь по одному.
 * 3. Очередь у дочернего процесса СИНХРОННАЯ намеренно. Так каждый из
 *    пятидесяти запросов пытается применить платёж и выдать товар прямо
 *    в обработчике — это максимальная конкуренция, а не имитация.
 *
 * Сервер поднимается самим тестом, а не берётся из docker-compose: набор Race
 * обязан проходить в CI, где никакого приложения заранее не запущено.
 */
abstract class RaceTestCase extends TestCase
{
    use DatabaseTruncation;

    protected const CONCURRENCY = 50;

    private const PORT = 8765;

    private const BOOT_TIMEOUT_SECONDS = 15;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(2);
        $this->server = null;

        parent::tearDown();
    }

    protected function baseUrl(): string
    {
        return 'http://127.0.0.1:'.self::PORT;
    }

    private function startServer(): void
    {
        $root = base_path();

        $server = new Process(
            ['php', '-S', '127.0.0.1:'.self::PORT, '-t', 'public', 'public/index.php'],
            $root,
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => config()->string('app.key'),
                'APP_DEBUG' => 'true',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => config()->string('database.connections.pgsql.host'),
                'DB_PORT' => config()->string('database.connections.pgsql.port'),
                'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
                'DB_USERNAME' => config()->string('database.connections.pgsql.username'),
                'DB_PASSWORD' => config()->string('database.connections.pgsql.password'),
                // Синхронная очередь: вся работа делается прямо в запросе,
                // и конкуренция получается настоящей, а не отложенной.
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                // Без этого встроенный сервер обрабатывает запросы по одному.
                'PHP_CLI_SERVER_WORKERS' => '16',
            ],
        );

        $server->setTimeout(null);
        $server->start();

        $this->server = $server;
        $this->waitUntilReady();
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + self::BOOT_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            try {
                if (Http::timeout(1)->get($this->baseUrl().'/up')->successful()) {
                    return;
                }
            } catch (ConnectionException) {
                // Сервер ещё поднимается — соединение отклоняется, это ожидаемо.
            }

            usleep(100_000);
        }

        $output = $this->server?->getErrorOutput() ?? '';

        throw new RuntimeException('Тестовый сервер не поднялся за '.self::BOOT_TIMEOUT_SECONDS." с.\n".$output);
    }
}
