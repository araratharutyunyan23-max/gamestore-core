<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\Actions\SweepStuckOrders;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Jobs\DeliverOrderJob;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Фоновая доводка зависших заказов.
 *
 * Главное свойство подметальщика — он НЕ делает ничего особенного. Он ставит
 * ту же самую задачу выдачи, что и обычный путь после оплаты, и вся защита от
 * задвоения работает без изменений.
 *
 * Отдельный «путь восстановления» со своей логикой — классический источник
 * второй выдачи: он расходится с основным ровно в тех переходах, которые
 * никто не догадался проверить.
 */
final class SweepStuckOrdersTest extends TestCase
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
    public function it_picks_up_an_order_stuck_awaiting_delivery(): void
    {
        $order = $this->paidOrderAgedBy(60);

        self::assertSame(1, app(SweepStuckOrders::class)->execute());

        Queue::assertPushed(DeliverOrderJob::class, 1);
    }

    #[Test]
    public function it_leaves_fresh_orders_alone(): void
    {
        // Заказ оплачен только что: задача выдачи уже стоит в очереди,
        // и подметальщику соревноваться с ней незачем.
        $this->paidOrderAgedBy(0);

        self::assertSame(0, app(SweepStuckOrders::class)->execute());
        Queue::assertNotPushed(DeliverOrderJob::class);
    }

    #[Test]
    public function it_does_not_touch_an_order_held_by_a_live_lease(): void
    {
        $order = $this->paidOrderAgedBy(60);

        // Аренда жива — прямо сейчас заказ выдаёт другой воркер.
        DB::table('orders')->where('id', $order->id)->update([
            'lease_token' => (string) Str::uuid(),
            'lease_owner' => 'busy-worker',
            'lease_expires_at' => now()->addMinutes(2),
        ]);

        self::assertSame(0, app(SweepStuckOrders::class)->execute());
        Queue::assertNotPushed(DeliverOrderJob::class);
    }

    #[Test]
    public function it_ignores_orders_that_are_already_finished(): void
    {
        $order = $this->paidOrderAgedBy(60);
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
        self::assertSame(0, app(SweepStuckOrders::class)->execute());
    }

    #[Test]
    public function a_delivered_order_cannot_be_returned_to_the_worklist_at_all(): void
    {
        // Самое сильное свойство доводки: она физически не может взять
        // в работу выданный заказ, потому что вернуть его в ожидающий статус
        // невозможно — триггер orders_no_final_downgrade запрещает выход
        // из финального состояния из любого места, включая psql и миграции.
        $order = $this->paidOrderAgedBy(60);
        app(DeliverOrder::class)->execute($order->public_id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/is final/');

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Paid->value,
            'status_changed_at' => now()->subHour(),
        ]);
    }

    #[Test]
    public function repeating_delivery_on_a_stuck_order_hands_out_only_one_code(): void
    {
        // Заказ завис в delivering с уже записанной выдачей — так выглядит
        // падение воркера сразу после фиксации кода.
        $order = $this->paidOrderAgedBy(60);
        app(DeliverOrder::class)->execute($order->public_id);

        DB::table('orders')->where('id', $order->id)->update([
            'lease_token' => null,
            'lease_expires_at' => null,
        ]);

        // Повторный прогон обычного пути выдачи безвреден: доводка ставит
        // именно его, никакой отдельной логики восстановления нет.
        app(DeliverOrder::class)->execute($order->public_id);
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(1, DB::table('deliveries')->where('order_id', $order->id)->count());
        self::assertSame(1, DB::table('license_keys')->whereNotNull('delivery_id')->count());
        self::assertSame(
            1,
            DB::table('ledger_transactions')->where('order_id', $order->id)->where('kind', 'order_delivered')->count(),
        );
    }

    private function paidOrderAgedBy(int $minutes, string $sku = 'KEY-CS2-PRIME'): Order
    {
        $this->travel(-$minutes)->minutes();

        try {
            $order = $this->makeOrder($sku);
            $eventId = 'evt_sweep_'.$order->public_id;

            $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
            app(ApplyPaymentEvent::class)->execute($eventId);
        } finally {
            $this->travelBack();
        }

        return $order->refresh();
    }
}
