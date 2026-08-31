<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Enums\SupplierName;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * База для сценариев с поставщиками.
 *
 * Обе заглушки поднимаются самим тестом отдельными процессами, а не берутся
 * из docker-compose: набор обязан проходить в CI, где никаких контейнеров
 * поставщика нет. Redis берётся тот же, что у приложения — он в CI есть.
 *
 * Поведение заглушек задаётся ПРЕДПИСАННО (`/admin/behavior`), а не долями
 * вероятностей из окружения. Тест на вероятности — это не тест: он то
 * воспроизводится, то нет, и красный прогон ничего не доказывает.
 */
abstract class SupplierTestCase extends TestCase
{
    use DatabaseTruncation;

    private const PORTS = ['A' => 8801, 'B' => 8802];

    /** @var array<string, Process> */
    private array $stubs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Очередь подменяется намеренно. Иначе приём вебхука синхронно
        // протягивает всю цепочку до выдачи, и явный вызов доставки в тесте
        // получает уже доставленный заказ — проверялся бы не тот путь.
        Queue::fake();

        $this->startStubs();

        // Клиенты должны идти в поднятые заглушки, а не в контейнеры compose.
        config([
            'suppliers.a.url' => $this->urlOf(SupplierName::A),
            'suppliers.b.url' => $this->urlOf(SupplierName::B),
            // Короткий таймаут: сценарии с зависанием не должны стоить минуты.
            'suppliers.issue_timeout' => 1.5,
            'suppliers.connect_timeout' => 1.0,
            'suppliers.max_processing' => 0.5,
            'suppliers.retries' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->stubs as $stub) {
            $stub->stop(2);
        }

        $this->stubs = [];

        parent::tearDown();
    }

    protected function urlOf(SupplierName $supplier): string
    {
        return 'http://127.0.0.1:'.self::PORTS[$supplier->value];
    }

    /** Предписать заглушке конкретное поведение на N следующих обращений. */
    protected function behaviour(SupplierName $supplier, string $mode, int $times = 1, int $delayMs = 0): void
    {
        Http::timeout(5)->put($this->urlOf($supplier).'/admin/behavior', [
            'mode' => $mode,
            'times' => $times,
            'delay_ms' => $delayMs,
        ])->throw();
    }

    protected function stock(SupplierName $supplier, string $sku, int $qty): void
    {
        Http::timeout(5)->put($this->urlOf($supplier).'/admin/stock/'.$sku, ['qty' => $qty])->throw();
    }

    /**
     * Сколько кодов заглушка реально выдала по заказу — независимый счётчик
     * со стороны поставщика. Наши таблицы могут врать; этот — нет.
     */
    protected function issuedCount(SupplierName $supplier, string $orderPublicId): int
    {
        $response = Http::timeout(5)->get($this->urlOf($supplier).'/issues', ['order_id' => $orderPublicId]);
        $issues = $response->json('issues');

        if (! is_array($issues)) {
            return 0;
        }

        return count(array_filter(
            $issues,
            static fn (mixed $row): bool => is_array($row) && ($row['state'] ?? null) === 'issued',
        ));
    }

    private function startStubs(): void
    {
        $script = base_path('docker/supplier/index.php');

        foreach (self::PORTS as $name => $port) {
            $stub = new Process(
                ['php', '-S', '127.0.0.1:'.$port, $script],
                base_path(),
                [
                    'SUPPLIER_NAME' => $name,
                    'SUPPLIER_REDIS_HOST' => config()->string('database.redis.default.host'),
                    'SUPPLIER_ERROR_RATE' => '0',
                    'SUPPLIER_TIMEOUT_RATE' => '0',
                    // Заглушка при проверке таймаутов спит по 30 секунд;
                    // однопоточный сервер не смог бы параллельно отвечать
                    // на probe, и сценарий проверял бы не то.
                    'PHP_CLI_SERVER_WORKERS' => '8',
                ],
            );

            $stub->setTimeout(null);
            $stub->start();
            $this->stubs[$name] = $stub;
        }

        $this->waitUntilReady();

        // Между тестами стор заглушки обязан быть чистым: выданные коды
        // переживают перезапуск процесса (в этом весь смысл AOF).
        foreach (SupplierName::cases() as $supplier) {
            Http::timeout(5)->post($this->urlOf($supplier).'/admin/reset')->throw();
        }
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            $ready = 0;

            foreach (SupplierName::cases() as $supplier) {
                try {
                    // 404 тоже означает «сервер отвечает»: у заглушки нет
                    // корневого маршрута, и это нормально.
                    Http::timeout(1)->get($this->urlOf($supplier).'/issues');
                    $ready++;
                } catch (ConnectionException) {
                    // ещё поднимается
                }
            }

            if ($ready === count(self::PORTS)) {
                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('Заглушки поставщиков не поднялись за 15 с.');
    }
}
