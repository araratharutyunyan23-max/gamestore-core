<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Правила из CLAUDE.md, за соблюдением которых следит тест, а не дисциплина.
 *
 * Эти проверки специально не зависят от Laravel: они должны падать даже тогда,
 * когда приложение не поднимается.
 */
final class ProjectStandardsTest extends TestCase
{
    #[Test]
    public function every_php_file_declares_strict_types(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn(['app', 'config', 'database', 'routes', 'tests']) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'declare(strict_types=1);')) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, "Файлы без declare(strict_types=1):\n".implode("\n", $offenders));
    }

    #[Test]
    public function phpstan_runs_at_level_9_without_suppressions(): void
    {
        $config = (string) file_get_contents(self::basePath('phpstan.neon'));

        self::assertStringContainsString('level: 9', $config, 'Уровень PHPStan должен быть 9.');
        self::assertStringContainsString('ignoreErrors: []', $config, 'ignoreErrors обязан оставаться пустым (CLAUDE.md §2.1).');
    }

    #[Test]
    public function phpstan_baseline_is_absent(): void
    {
        // Baseline превращает "level 9 без ошибок" в неправду задним числом.
        foreach (['phpstan-baseline.neon', 'phpstan-baseline.php'] as $baseline) {
            self::assertFileDoesNotExist(self::basePath($baseline), "Baseline запрещён: {$baseline}");
        }
    }

    #[Test]
    public function http_calls_go_through_the_framework_client(): void
    {
        // Сырой curl в проекте на Laravel — это ручной boilerplate вокруг того же
        // curl_multi, который Http-клиент уже даёт поверх Guzzle: инициализация,
        // проверки на false, ручной json_decode, лишние сужения типов для level 9.
        // Прямой new GuzzleHttp\Client тоже запрещён: он обходит конфигурацию
        // таймаутов, ретраев и Http::fake() в тестах.
        $forbidden = [
            'curl_init(' => 'Http::post()/Http::get()',
            'curl_exec(' => 'Http::post()/Http::get()',
            'curl_multi_init(' => 'Http::pool()',
            'curl_setopt' => 'Http::withOptions()',
            'new Client(' => 'фасад Http',
            'new \\GuzzleHttp\\Client(' => 'фасад Http',
        ];

        $offenders = [];

        foreach (self::phpFilesIn(['app']) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $needle => $replacement) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($file)." → {$needle} вместо {$replacement}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "HTTP-вызовы идут только через Http-клиент Laravel:\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function every_queued_job_handles_its_own_failure(): void
    {
        // Задача без failed() оставляет заказ в промежуточном статусе навсегда:
        // попытки кончились, задачи больше нет, а ни один восстановительный
        // путь такой заказ не видит (CLAUDE.md §1.3).
        $offenders = [];

        foreach (self::phpFilesIn(['app/Jobs']) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'implements ShouldQueue')) {
                continue;
            }

            if (! str_contains($contents, 'public function failed(')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, "Джобы без failed():\n".implode("\n", $offenders));
    }

    #[Test]
    public function controllers_contain_no_persistence_or_business_logic(): void
    {
        $forbidden = ['DB::', 'Cache::', 'Http::', 'Queue::', '::query(', '->save(', 'DB::transaction'];
        $offenders = [];

        foreach (self::phpFilesIn(['app/Http/Controllers']) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file.' → '.$needle;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Контроллер вызывает только сервис (CLAUDE.md §1.1). Нарушения:\n".implode("\n", $offenders),
        );
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private static function phpFilesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $path = self::basePath($directory);

            if (! is_dir($path)) {
                continue;
            }

            /** @var iterable<string, SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
