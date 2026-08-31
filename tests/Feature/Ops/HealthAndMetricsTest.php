<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Domain\Reconciliation\Actions\RunReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * §7 обещает /health и /metrics. Тесты проверяют не наличие маршрутов,
 * а то, что они отвечают на вопросы, ради которых заведены.
 */
final class HealthAndMetricsTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('ops.token', 'ops-secret');
    }

    #[Test]
    public function health_is_degraded_while_reconciliation_has_never_run(): void
    {
        // Это главный сценарий, ради которого возраст сверки вообще меряется:
        // база и кеш живы, торговля идёт, а расхождения никто не ищет.
        // Снаружи такая система выглядит совершенно здоровой.
        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok')
            ->assertJsonPath('checks.reconciliation.status', 'stale')
            ->assertJsonPath('checks.reconciliation.age_seconds', null);
    }

    #[Test]
    public function health_goes_green_once_reconciliation_has_run(): void
    {
        app(RunReconciliation::class)->execute(full: false);

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.reconciliation.status', 'ok');
    }

    #[Test]
    public function health_needs_no_token(): void
    {
        // Живость опрашивает балансировщик, у которого токена нет и быть
        // не должно. Закрытый /health — это health, который никто не читает.
        config()->set('ops.token', '');

        $this->getJson('/health')->assertStatus(503);
    }

    #[Test]
    public function metrics_are_closed_by_the_ops_token(): void
    {
        // По счётчикам заказов и незакрытой предоплаты читается оборот.
        $this->get('/metrics')->assertForbidden();
        $this->get('/metrics', ['X-Ops-Token' => 'wrong'])->assertForbidden();
    }

    #[Test]
    public function metrics_expose_order_counts_and_money_invariants(): void
    {
        $order = $this->makeOrder();
        $this->postWebhook($this->webhookPayload($order))->assertOk();

        $body = $this->get('/metrics', ['X-Ops-Token' => 'ops-secret'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->getContent();

        self::assertIsString($body);

        self::assertStringContainsString('# TYPE gamestore_orders gauge', $body);
        self::assertMatchesRegularExpression('/gamestore_orders\{status="[a-z_]+"\} \d+/', $body);

        // Расхождение журнала — единственная метрика, у которой есть
        // правильное значение: ноль. Всё остальное — наблюдение.
        self::assertStringContainsString('gamestore_ledger_imbalance_minor 0', $body);
        self::assertStringContainsString('gamestore_reconciliation_age_seconds -1', $body);
    }

    #[Test]
    public function an_empty_bucket_still_emits_a_line(): void
    {
        // Исчезнувшая метрика в мониторинге неотличима от сломавшегося
        // экспортера, поэтому пустая группа отдаёт ноль, а не пустоту.
        $body = $this->get('/metrics', ['X-Ops-Token' => 'ops-secret'])->assertOk()->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('gamestore_open_findings{severity="none"} 0', $body);
    }
}
