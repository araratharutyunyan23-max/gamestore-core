<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;
use Throwable;

/**
 * «Оплачено = выдано + возвращено» — на НАСТОЯЩЕМ коммите.
 *
 * Инвариант ТЗ 1.3 записан так, что его проверяет база: у заказа, дошедшего до
 * конечного состояния, остаток по customer_prepayment равен нулю. Обнулить его
 * может только выдача (в выручку) или возврат (в долг перед покупателем) —
 * третьего способа нет, и именно это здесь и утверждается.
 *
 * Тест живёт в состязательном наборе по той же причине, что и проверка баланса
 * журнала: ограничение отложенное и срабатывает на COMMIT, а под
 * RefreshDatabase транзакция не коммитится никогда. В обычном наборе такой
 * тест был бы зелёным, ничего не проверяя.
 */
final class SettlementGuardTest extends TestCase
{
    use DatabaseTruncation;
    use OrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function an_order_cannot_be_closed_while_the_customer_money_is_still_open(): void
    {
        // Заказ оплачен, товар не выдан и деньги не возвращены. Объявить такой
        // заказ выданным — значит забрать деньги и не отдать ничего. Это и есть
        // та ошибка, ради которой инвариант существует.
        $order = $this->paidOrder();

        self::assertGreaterThan(0, $this->openPrepayment($order), 'Предоплата обязана быть открытой.');

        $failure = $this->attempt(function () use ($order): void {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => OrderStatus::Delivered->value,
                'status_changed_at' => now(),
            ]);
        });

        self::assertInstanceOf(
            PDOException::class,
            $failure,
            'Заказ закрылся с незакрытой предоплатой — деньги покупателя пропали бы молча.',
        );
        self::assertStringContainsString('prepayment are still open', $failure->getMessage());

        self::assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    #[Test]
    public function the_same_applies_to_a_partial_close(): void
    {
        // Частичный расчёт — самая вероятная дыра: соблазн объявить заказ
        // «частично выданным» и на этом успокоиться, не вернув за остальное.
        $order = $this->paidOrder();

        $failure = $this->attempt(function () use ($order): void {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => OrderStatus::PartiallyDelivered->value,
                'status_changed_at' => now(),
            ]);
        });

        self::assertInstanceOf(PDOException::class, $failure, 'Частичный расчёт прошёл без возврата.');

        // Сообщение проверяется точно. Без этого тест был зелёным по
        // ПОСТОРОННЕЙ причине: фикстура успевала выдать заказ, и падал
        // совсем другой триггер — запрет выхода из финального статуса.
        self::assertStringContainsString('prepayment are still open', $failure->getMessage());
    }

    #[Test]
    public function a_properly_delivered_order_closes_normally(): void
    {
        // Обратная сторона: ограничение обязано пропускать нормальный заказ,
        // иначе «защита» — это просто запрет работать.
        $order = $this->paidOrder();

        self::assertGreaterThan(0, $this->openPrepayment($order));

        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
        self::assertSame(0, $this->openPrepayment($order));
    }

    /**
     * Остаток по обязательству перед покупателем, в копейках.
     */
    private function openPrepayment(Order $order): int
    {
        $total = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.order_id', $order->id)
            ->where('a.code', 'customer_prepayment')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS balance")
            ->value('balance');

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @param  Closure(): void  $work
     */
    private function attempt(Closure $work): ?Throwable
    {
        try {
            DB::transaction($work);

            return null;
        } catch (Throwable $e) {
            // PDOException, а не QueryException: отложенное ограничение падает
            // на COMMIT, то есть вне подготовленного запроса, и Laravel такую
            // ошибку не заворачивает.
            return $e;
        }
    }

    private function paidOrder(): Order
    {
        // Очередь глушится: признание оплаты ставит задачу выдачи, и без этого
        // заказ успевает выдаться до того, как тест дойдёт до проверки. Нам
        // нужен именно ОПЛАЧЕННЫЙ И НЕ ВЫДАННЫЙ заказ — состояние, в котором
        // обязательство перед покупателем открыто.
        Queue::fake();

        $order = $this->makeOrder();
        $eventId = 'evt_settle_guard_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
