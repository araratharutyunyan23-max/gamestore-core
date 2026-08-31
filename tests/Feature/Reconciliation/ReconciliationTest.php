<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Domain\Reconciliation\DTO\Anomaly;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Enums\FindingSeverity;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Сверка отвечает на два вопроса задания: «оплачен, но не выдан» и
 * «выдан, но не оплачен».
 *
 * Отдельно проверяется, что она МОЛЧИТ на здоровых данных. Сверка, которая
 * шумит на штатной работе, бесполезна: её перестают читать через неделю,
 * и настоящая аномалия теряется в потоке ложных.
 */
final class ReconciliationTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    #[Test]
    public function a_healthy_delivered_order_produces_no_anomalies(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $report = app(RunReconciliation::class)->execute(full: true);

        self::assertTrue($report->isHealthy(), 'Сверка нашла проблему на полностью здоровом заказе.');
        self::assertSame(0, $report->criticalCount());
        self::assertSame(0, $report->ledgerImbalanceMinor);

        // Обязательство закрыто выдачей: денег «в пути» не осталось.
        self::assertSame(0, $report->openPrepaymentMinor);
    }

    #[Test]
    public function it_finds_an_order_that_is_paid_but_not_delivered(): void
    {
        // Заказ оплачен час назад и до сих пор не выдан.
        $order = $this->paidOrderAgedBy(60);

        $report = app(RunReconciliation::class)->execute();

        self::assertFalse($report->isHealthy());
        $this->assertHasAnomaly($report->anomalies, FindingKind::PaidNotDelivered, $order->public_id);

        // Второй, независимый признак той же картины: обязательство перед
        // клиентом открыто ровно на сумму заказа.
        self::assertSame(129000, $report->openPrepaymentMinor);
    }

    #[Test]
    public function it_finds_an_order_that_is_delivered_but_not_paid(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        // Платёж отозван уже после выдачи — товар ушёл, денег нет.
        DB::table('order_payment_states')->where('order_id', $order->id)->update(['state' => 'failed']);

        $report = app(RunReconciliation::class)->execute();

        self::assertFalse($report->isHealthy());
        $this->assertHasAnomaly($report->anomalies, FindingKind::DeliveredNotPaid, $order->public_id);
    }

    #[Test]
    public function waiting_for_restock_is_a_warning_and_not_an_incident(): void
    {
        // Критерий приёмки №6 говорит, что пустой остаток — восстановимое
        // состояние. Если считать его инцидентом, система будет «нездорова»
        // ровно тогда, когда работает штатно.
        $order = $this->paidOrderAgedBy(60, 'KEY-EFT');

        DB::table('license_keys')->where('product_id', $order->product_id)->update(['status' => 'reserved']);
        DB::table('product_stock')->where('product_id', $order->product_id)->update(['available_count' => 0]);

        app(DeliverOrder::class)->execute($order->public_id);
        self::assertSame(OrderStatus::OutOfStock, $order->refresh()->status);

        $report = app(RunReconciliation::class)->execute();

        $this->assertHasAnomaly($report->anomalies, FindingKind::AwaitingRestock, $order->public_id);
        self::assertSame(FindingSeverity::Warning, FindingKind::AwaitingRestock->severity());
        self::assertTrue($report->isHealthy(), 'Ожидание склада не должно делать систему нездоровой.');
    }

    #[Test]
    public function it_finds_a_payment_event_that_was_never_applied(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhookPayload($order, ['event_id' => 'evt_lost']);
        $this->postWebhook($payload)->assertOk();

        // Задача потерялась: событие принято, но никогда не применено.
        DB::table('payment_events')->where('event_id', 'evt_lost')
            ->update(['received_at' => now()->subMinutes(5)]);

        $report = app(RunReconciliation::class)->execute();

        self::assertFalse($report->isHealthy());
        $this->assertHasAnomaly($report->anomalies, FindingKind::UnappliedPayment, 'evt_lost');
    }

    #[Test]
    public function it_finds_stock_counter_drift(): void
    {
        // Ключи залиты мимо единой точки пополнения — счётчик занижен.
        // Дрейф ловится сравнением с ИСТОЧНИКОМ ИСТИНЫ, а не проверкой
        // самого счётчика: он-то как раз и не менялся.
        $productId = DB::table('products')->where('sku', 'KEY-GTA5')->value('id');
        DB::table('product_stock')->where('product_id', $productId)->update(['available_count' => 0]);

        $report = app(RunReconciliation::class)->execute(full: true);

        $this->assertHasAnomaly($report->anomalies, FindingKind::StockDrift, 'KEY-GTA5');
    }

    #[Test]
    public function the_endpoint_is_closed_without_a_token(): void
    {
        $this->getJson('/ops/reconciliation')->assertForbidden();
    }

    #[Test]
    public function the_endpoint_returns_409_when_something_is_wrong(): void
    {
        config(['ops.token' => 'secret-token']);

        $this->paidOrderAgedBy(60);

        // 409 при нездоровье, чтобы эндпоинт можно было повесить прямо
        // в мониторинг без разбора тела ответа.
        $this->withHeader('X-Ops-Token', 'secret-token')
            ->getJson('/ops/reconciliation')
            ->assertStatus(409)
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('summary.open_prepayment_minor', 129000);
    }

    #[Test]
    public function the_endpoint_returns_200_when_everything_is_fine(): void
    {
        config(['ops.token' => 'secret-token']);

        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $this->withHeader('X-Ops-Token', 'secret-token')
            ->getJson('/ops/reconciliation')
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('summary.ledger_imbalance_minor', 0);
    }

    /**
     * @param  list<Anomaly>  $anomalies
     */
    private function assertHasAnomaly(array $anomalies, FindingKind $kind, string $subject): void
    {
        $found = array_filter(
            $anomalies,
            static fn (object $a): bool => $a->kind === $kind && $a->subject === $subject,
        );

        self::assertNotSame([], $found, sprintf(
            'Сверка не нашла %s по субъекту %s. Найдено: %s',
            $kind->value,
            $subject,
            $anomalies === []
                ? '—'
                : implode(', ', array_map(static fn (object $a): string => $a->kind->value.':'.$a->subject, $anomalies)),
        ));
    }

    private function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
    {
        $order = $this->makeOrder($sku);
        $eventId = 'evt_rec_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }

    /**
     * «Состарить» заказ можно только одним способом — создать его в прошлом.
     *
     * Подделать время задним числом нельзя: журнал объявлен только на
     * добавление, и триггер ledger_entries_immutable отвергает UPDATE.
     * Это ровно та защита, ради которой он написан.
     */
    private function paidOrderAgedBy(int $minutes, string $sku = 'KEY-CS2-PRIME'): Order
    {
        $this->travel(-$minutes)->minutes();

        try {
            $order = $this->paidOrder($sku);
        } finally {
            $this->travelBack();
        }

        return $order->refresh();
    }
}
