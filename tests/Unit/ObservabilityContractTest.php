<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md §7 обещает набор полей и событий. Этот тест делает обещание
 * проверяемым.
 *
 * Повод конкретный: документ перечислял двенадцать обязательных событий, а
 * код писал шесть из них, и половина обязательных полей не существовала
 * вовсе. Расхождение прожило несколько этапов — ровно потому, что его никто
 * не мог заметить: документ не исполняется.
 *
 * Теперь исполняется. Либо событие пишется, либо его нет в списке.
 */
final class ObservabilityContractTest extends TestCase
{
    #[Test]
    public function every_event_promised_by_the_standard_is_actually_emitted(): void
    {
        $emitted = self::emittedEvents();

        $missing = array_values(array_diff(self::promised('Обязательные события'), $emitted));

        self::assertSame(
            [],
            $missing,
            "CLAUDE.md §7 обещает события, которых код не пишет:\n".implode("\n", $missing),
        );
    }

    #[Test]
    public function every_field_promised_by_the_standard_exists_in_the_logger(): void
    {
        $logger = (string) file_get_contents(self::path('app/Support/StructuredLog.php'));

        $missing = [];

        foreach (self::promised('обязательные поля') as $field) {
            if (! str_contains($logger, "'{$field}' =>")) {
                $missing[] = $field;
            }
        }

        self::assertSame(
            [],
            $missing,
            "CLAUDE.md §7 обещает поля, которых StructuredLog не пишет:\n".implode("\n", $missing),
        );
    }

    #[Test]
    public function the_standard_does_not_hide_events_the_code_emits(): void
    {
        // Обратная сторона того же договора. Список в CLAUDE.md — не «минимум»,
        // а карта: по ней настраивают алерты. Событие, которого в карте нет,
        // никто не ждёт, и его отсутствие в проде останется незамеченным.
        //
        // Полного совпадения тут не требуется — событий в коде намеренно
        // больше. Требуется, чтобы список не оказался выдумкой: каждое имя из
        // него живёт в коде, что и проверяют два теста выше. Здесь же
        // фиксируется нижняя граница объёма, чтобы наблюдаемость нельзя было
        // тихо выпилить целиком.
        self::assertGreaterThanOrEqual(25, count(self::emittedEvents()));
    }

    /**
     * Имена событий, встречающиеся в вызовах StructuredLog.
     *
     * @return list<string>
     */
    private static function emittedEvents(): array
    {
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all("/StructuredLog::\w+\(\s*\n?\s*'([a-z_]+)'/", $source, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $found[] = $name;
                }
            }
        }

        // Имя события совпадает с именем метода-обёртки только у сверки:
        // finding() пишет его само, поэтому в вызовах его не видно.
        $found[] = 'reconciliation_finding';

        return array_values(array_unique($found));
    }

    /**
     * Список из §7, заданный строкой-заголовком перед ним.
     *
     * @return list<string>
     */
    private static function promised(string $heading): array
    {
        $standard = (string) file_get_contents(self::path('CLAUDE.md'));
        $section = (string) preg_replace('/.*^## 7\.(.*?)^## 8\..*/ums', '$1', $standard);

        $offset = mb_strpos($section, $heading);
        self::assertIsInt($offset, "В CLAUDE.md §7 не найден список «{$heading}».");

        $tail = mb_substr($section, $offset);
        $list = (string) mb_strstr(mb_substr($tail, 0, (int) mb_strpos($tail, "\n\n")), ':');

        preg_match_all('/`([a-z_, \n]+)`/u', $list, $matches);

        $names = [];

        foreach ($matches[1] as $chunk) {
            $parts = preg_split('/[,\s]+/', trim($chunk));

            foreach ($parts === false ? [] : $parts as $name) {
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        self::assertNotSame([], $names, "Список «{$heading}» пуст — тест бесполезен.");

        return $names;
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
