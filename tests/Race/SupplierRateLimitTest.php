<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\Actions\DrainDeliveryQueue;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Задача 3 (бонус): всплеск заказов при ограниченной квоте поставщика.
 *
 * Проверяется не «код вызывает лимитер», а сам результат: сколько обращений
 * к поставщику реально ушло. Измеряется по журналу попыток — по строкам,
 * которые пишет сама система при каждом обращении, а не по доверию к тому,
 * что ограничитель кто-то спросил.
 *
 * Лимит здесь маленький, а окно короткое. Это не поддавки: свойство,
 * которое проверяется, — «в любое окно не больше N», и оно не зависит от
 * величины N. Зато тест укладывается в секунды, а не в минуты, и потому
 * его будут гонять.
 */
final class SupplierRateLimitTest extends SupplierTestCase
{
    use OrderFixtures;

    private const SKU = 'STEAM-TOPUP-500';

    private const LIMIT = 3;

    private const ORDERS = 10;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('suppliers.rate_limit_per_minute', self::LIMIT);
        config()->set('suppliers.rate_limit_window_seconds', 60);

        // Квота хранится в Redis и переживает тест. Между прогонами её надо
        // сбрасывать, иначе второй тест начинается с уже занятыми слотами
        // и падает по причине, к нему не относящейся.
        foreach (SupplierName::cases() as $supplier) {
            app('redis')->connection()->del('supplier_rate:'.$supplier->value);
        }

        Queue::fake();
    }

    #[Test]
    public function the_supplier_quota_is_never_exceeded(): void
    {
        $this->stock(SupplierName::A, self::SKU, 100);

        $orders = $this->paidOrders(self::ORDERS);

        foreach ($orders as $order) {
            app(DeliverOrder::class)->execute($order->public_id);
        }

        // Обращений к поставщику ровно столько, сколько разрешает квота.
        // Считается по журналу попыток: это записи, которые система делает
        // ПЕРЕД сетевым вызовом, то есть независимый счётчик.
        self::assertSame(
            self::LIMIT,
            DB::table('delivery_attempts')->count(),
            'Обращений к поставщику больше, чем разрешает квота.',
        );
    }

    #[Test]
    public function nothing_is_lost_when_the_quota_runs_out(): void
    {
        $this->stock(SupplierName::A, self::SKU, 100);

        $orders = $this->paidOrders(self::ORDERS);

        foreach ($orders as $order) {
            app(DeliverOrder::class)->execute($order->public_id);
        }

        // Ни один заказ не потерян: то, что не прошло по квоте, ждёт очереди.
        $waiting = DB::table('order_items')
            ->whereIn('status', [
                OrderItemStatus::Pending->value,
                OrderItemStatus::Delivering->value,
            ])
            ->count();

        $delivered = DB::table('deliveries')->count();

        self::assertSame(
            self::ORDERS,
            $waiting + $delivered,
            'Часть заказов исчезла: не выдана и не стоит в очереди.',
        );

        // И у отложенных проставлено время следующей попытки в будущем —
        // очередь это строки в базе, а не список в памяти воркера.
        self::assertGreaterThan(
            0,
            DB::table('order_items')->where('next_action_at', '>', now())->count(),
            'Отложенные позиции не получили времени следующей попытки.',
        );
    }

    #[Test]
    public function the_queue_is_served_once_the_window_moves_on(): void
    {
        $this->stock(SupplierName::A, self::SKU, 100);

        $orders = $this->paidOrders(self::ORDERS);

        foreach ($orders as $order) {
            app(DeliverOrder::class)->execute($order->public_id);
        }

        $firstWave = DB::table('delivery_attempts')->count();
        self::assertSame(self::LIMIT, $firstWave);

        // Окно уехало: квота свободна. Позиции, отложенные до этого момента,
        // обязаны подобраться сами.
        foreach (SupplierName::cases() as $supplier) {
            app('redis')->connection()->del('supplier_rate:'.$supplier->value);
        }

        DB::table('order_items')->update(['next_action_at' => now()->subSecond()]);

        $dispatched = app(DrainDeliveryQueue::class)->execute();

        self::assertGreaterThan(0, $dispatched, 'Очередь не подобрала отложенные заказы.');
    }

    #[Test]
    public function paid_orders_are_served_before_unpaid_ones(): void
    {
        // ТЗ 3.3. Приоритет проставляется в момент признания оплаты, поэтому
        // сравниваются именно оплаченный и неоплаченный заказы.
        $paid = $this->paidOrders(1)[0];
        $unpaid = $this->makeOrder(self::SKU);

        $paidPriority = DB::table('order_items')->where('order_id', $paid->id)->value('priority');
        $unpaidPriority = DB::table('order_items')->where('order_id', $unpaid->id)->value('priority');

        self::assertIsNumeric($paidPriority);
        self::assertIsNumeric($unpaidPriority);

        self::assertGreaterThan(
            (int) $unpaidPriority,
            (int) $paidPriority,
            'Оплаченный заказ не обгоняет неоплаченный в очереди.',
        );
    }

    /**
     * @return list<Order>
     */
    private function paidOrders(int $count): array
    {
        $orders = [];

        for ($i = 0; $i < $count; $i++) {
            $order = $this->makeOrder(self::SKU);
            $eventId = 'evt_rate_'.$order->public_id;

            $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
            app(ApplyPaymentEvent::class)->execute($eventId);

            $orders[] = $order->refresh();
        }

        return $orders;
    }
}
