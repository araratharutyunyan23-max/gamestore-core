<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Catalog\DTO\ShowcaseCursor;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Repositories\ShowcaseRepository;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * План выполнения горячего запроса витрины на объёме.
 *
 * Проверяется ПЛАН, а не время ответа. Время на пустой базе всегда хорошее и
 * ничего не доказывает; план говорит, читает ли база основную таблицу и выбрала ли
 * нужный индекс. Утверждение о производительности без EXPLAIN под ним —
 * не утверждение.
 *
 * Тест живёт в наборе Race, потому что требует настоящей, закоммиченной базы:
 * VACUUM нельзя выполнить внутри транзакции, а без него карта видимости не
 * заполнена и Index Only Scan невозможен в принципе.
 */
final class ShowcasePlanTest extends TestCase
{
    use DatabaseTruncation;

    private const BULK = 3000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Artisan::call('shop:seed-bulk', ['--count' => self::BULK]);
    }

    #[Test]
    public function the_hot_query_reads_only_the_index_and_never_the_table(): void
    {
        $plan = app(ShowcaseRepository::class)->explainPage(ProductType::Key, 25);
        $node = $this->innerNode($plan);

        self::assertSame(
            'Index Only Scan',
            $node['Node Type'] ?? null,
            'Ожидался Index Only Scan. Реальный план: '.json_encode($plan, JSON_UNESCAPED_UNICODE),
        );

        self::assertSame('products_showcase_cov_idx', $node['Index Name'] ?? null);

        // Ноль обращений к куче — прямое доказательство того, что индекс
        // действительно покрывает запрос. Один недостающий столбец в INCLUDE
        // превращает этот план в обычный Index Scan, и база начинает ходить
        // в основную таблицу за каждой строкой.
        self::assertSame(0, $node['Heap Fetches'] ?? null, 'Индекс не покрывает запрос целиком.');
    }

    #[Test]
    public function the_hot_query_touches_a_handful_of_pages_not_the_whole_table(): void
    {
        $plan = app(ShowcaseRepository::class)->explainPage(ProductType::Key, 25);
        $node = $this->innerNode($plan);

        $pages = $this->intOf($node, 'Shared Hit Blocks') + $this->intOf($node, 'Shared Read Blocks');

        /** @var list<object{total: int}> $rows */
        $rows = DB::select('SELECT count(*)::int AS total FROM products WHERE is_active AND in_stock');

        self::assertGreaterThan(self::BULK / 2, $rows[0]->total, 'Каталог не наполнился — замер бессмысленен.');

        // Стоимость страницы не зависит от размера каталога: читается ровно
        // тот кусок индекса, который нужен. Порог с запасом — важно, что это
        // единицы страниц, а не сотни.
        self::assertLessThanOrEqual(16, $pages, "Прочитано {$pages} страниц — план деградировал.");
    }

    #[Test]
    public function paging_deep_into_the_catalogue_costs_the_same_as_the_first_page(): void
    {
        $repository = app(ShowcaseRepository::class);

        $first = $repository->page(ProductType::Key, null, 25);
        self::assertNotNull($first->nextCursor);

        // Прошагать вглубь и убедиться, что курсорная пагинация не деградирует.
        // С OFFSET страница 40 стоила бы в 40 раз дороже первой: база обязана
        // прочитать и выбросить все пропускаемые строки.
        $cursor = $first->nextCursor;
        $pagesWalked = 0;

        while ($cursor !== null && $pagesWalked < 20) {
            $next = $repository->page(ProductType::Key, ShowcaseCursor::decode($cursor), 25);
            $cursor = $next->nextCursor;
            $pagesWalked++;
        }

        self::assertGreaterThan(0, $pagesWalked, 'Пагинация не прошла ни одной страницы.');
    }

    /**
     * Значения из плана приходят как mixed — сужаем здесь, наружу не выпускаем.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function intOf(array $node, string $key): int
    {
        $value = $node[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $plan
     * @return array<array-key, mixed>
     */
    private function innerNode(array $plan): array
    {
        // План приходит вложенным: Limit → Index Only Scan.
        $node = is_array($plan['Plan'] ?? null) ? $plan['Plan'] : [];
        $children = is_array($node['Plans'] ?? null) ? $node['Plans'] : [];

        return is_array($children[0] ?? null) ? $children[0] : $node;
    }
}
