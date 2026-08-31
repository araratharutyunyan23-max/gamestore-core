<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Repositories\ShowcaseRepository;
use Illuminate\Console\Command;

/**
 * Разбор плана выполнения горячего запроса витрины.
 *
 * Существует потому, что утверждение о производительности без EXPLAIN под ним —
 * это не утверждение. Команда печатает то, что можно вставить в README и
 * защитить на собеседовании.
 */
final class ExplainShowcaseCommand extends Command
{
    protected $signature = 'shop:explain-showcase {--type=key} {--limit=25}';

    protected $description = 'Показать план выполнения горячего запроса витрины';

    public function handle(ShowcaseRepository $showcase): int
    {
        $type = ProductType::tryFrom((string) $this->option('type'));
        $plan = $showcase->explainPage($type, (int) $this->option('limit'));

        // План приходит вложенным: Limit -> Index Only Scan. Интересен внутренний
        // узел — именно он говорит, читалась ли основная таблица.
        $node = $this->node($plan, 'Plan');
        $children = $this->node($node, 'Plans');
        $inner = $children === [] ? $node : $this->node($children, '0');

        $this->components->twoColumnDetail('Узел плана', $this->str($inner, 'Node Type'));
        $this->components->twoColumnDetail('Индекс', $this->str($inner, 'Index Name'));
        $this->components->twoColumnDetail('Обращений к куче', $this->str($inner, 'Heap Fetches'));
        $this->components->twoColumnDetail('Страниц из кэша', $this->str($inner, 'Shared Hit Blocks'));
        $this->components->twoColumnDetail('Страниц с диска', $this->str($inner, 'Shared Read Blocks'));
        $this->components->twoColumnDetail('Строк отдано', $this->str($inner, 'Actual Rows'));
        $this->components->twoColumnDetail('Время выполнения, мс', $this->str($plan, 'Execution Time'));

        $this->newLine();
        $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Достать вложенный узел плана, не выпуская mixed наружу.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function node(array $node, string $key): array
    {
        $value = $node[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function str(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        return is_scalar($value) ? (string) $value : '—';
    }
}
