<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Ordering\Actions\SettleAbandonedItems;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Задача 2: поставщик, которому нельзя доверять.
 *
 * До этого шага заглушка вела себя честно: могла упасть, промолчать, отказать —
 * но никогда не врала. Здесь она врёт: отдаёт один код двум запросам, отдаёт
 * код от другого товара, отвечает ошибкой после настоящей выдачи.
 *
 * Проверяется, что защита стоит на НАШЕЙ стороне. Ни одна из этих проверок не
 * может опираться на добросовестность поставщика — иначе она проверяет
 * не то, что заявлено.
 */
final class DishonestSupplierTest extends SupplierTestCase
{
    use OrderFixtures;

    private const SKU = 'STEAM-TOPUP-500';

    #[Test]
    public function one_code_never_reaches_two_buyers_even_when_the_supplier_sends_a_duplicate(): void
    {
        // Гейт шага: два покупателя, поставщик отдаёт обоим ОДИН код.
        $this->stock(SupplierName::A, self::SKU, 5);

        $first = $this->paidOrder();
        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($first->public_id));

        // Второму заказу поставщик A отдаст код, уже отданный первому.
        // Второй поставщик товара не имеет — значит уйти к нему не выйдет,
        // и позиция обязана честно остаться невыданной.
        $this->behaviour(SupplierName::A, 'duplicate_code', times: 1);
        $this->stock(SupplierName::B, self::SKU, 0);

        $second = $this->paidOrder();
        app(DeliverOrder::class)->execute($second->public_id);

        // Главное: код у первого покупателя, и только у него.
        self::assertSame(1, DB::table('deliveries')->where('order_id', $first->id)->count());
        self::assertSame(
            0,
            DB::table('deliveries')->where('order_id', $second->id)->count(),
            'Чужой код ушёл второму покупателю.',
        );

        // Ни одного кода в двух руках во всей базе.
        $codes = DB::table('deliveries')->pluck('code_hash');
        self::assertSame($codes->count(), $codes->unique()->count(), 'Один код выдан дважды.');

        // Расхождение зафиксировано, а не проглочено как успех.
        self::assertSame(
            1,
            DB::table('reconciliation_findings')
                ->where('kind', 'supplier_returned_duplicate_code')
                ->count(),
            'Дубль от поставщика не попал в сверку — разбирать будет нечего.',
        );
    }

    #[Test]
    public function a_code_issued_for_another_product_is_refused(): void
    {
        // Поставщик объявил выдачу по другому товару. Проверить сам код мы не
        // можем — что он открывает, снаружи не узнать. Но заявленный товар
        // сверить обязаны: иначе покупатель получит не то, за что заплатил.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 0);
        $this->behaviour(SupplierName::A, 'foreign_code', times: 1);

        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());

        self::assertSame(
            1,
            DB::table('reconciliation_findings')
                ->where('kind', 'supplier_returned_foreign_code')
                ->count(),
        );

        // Код записан как отвергнутый, а не выброшен: поставщик списал его
        // у себя, и расхождение придётся разбирать.
        self::assertSame(
            1,
            DB::table('supplier_issued_codes')->where('disposition', 'rejected')->count(),
            'Отвергнутый код не оставил следа — расхождение с поставщиком не разобрать.',
        );
    }

    #[Test]
    public function an_error_after_a_real_issue_does_not_buy_a_second_code(): void
    {
        // Ловушка задачи 2.3 в самой злой форме: ответ ПРИШЁЛ и выглядит как
        // отказ, хотя код выдан и списан. Наивная логика сочтёт это доказанным
        // «не выдано» и купит второй код у того же или другого поставщика.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 5);
        $this->behaviour(SupplierName::A, 'error_after_issue', times: 1);

        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        // Сколько кодов поставщик A реально списал по этому заказу — считает
        // он сам, а не мы. Это и есть независимая проверка.
        self::assertLessThanOrEqual(
            1,
            $this->issuedCount(SupplierName::A, $order->public_id),
            'После ошибки поставщик выдал второй код — повтор ушёл с новым request_id.',
        );

        // И у покупателя в любом случае не больше одного кода.
        self::assertLessThanOrEqual(1, DB::table('deliveries')->where('order_id', $order->id)->count());
    }

    #[Test]
    public function a_rejected_code_leaves_the_item_recoverable_not_delivered(): void
    {
        // Отказ принять код не имеет права выглядеть как выдача. Позиция
        // остаётся невыданной и восстановимой — её подберёт повтор, а если
        // не выйдет, за неё вернут деньги (Ш3).
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 0);
        $this->behaviour(SupplierName::A, 'foreign_code', times: 1);

        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $status = DB::table('order_items')->where('order_id', $order->id)->value('status');

        self::assertNotSame(OrderItemStatus::Delivered->value, $status, 'Позиция объявлена выданной без выдачи.');
        self::assertContains($status, [
            OrderItemStatus::DeliveryFailed->value,
            OrderItemStatus::Delivering->value,
        ]);
    }

    #[Test]
    public function a_dishonest_supplier_is_settled_without_human_hands(): void
    {
        // ТЗ 2.4: расхождения разбираются автоматически. Проверяется весь путь
        // целиком — от обмана до закрытого заказа, без единого ручного шага.
        $this->stock(SupplierName::A, self::SKU, 5);
        $this->stock(SupplierName::B, self::SKU, 0);
        $this->behaviour(SupplierName::A, 'foreign_code', times: 5);

        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        // Позиция не выдана и заказ не закрыт: пока идут повторы, закрывать
        // его рано.
        self::assertSame(0, DB::table('deliveries')->where('order_id', $order->id)->count());

        // Терпение вышло — фоновый расчёт закрывает заказ возвратом.
        $this->travel(2)->hours();
        app(SettleAbandonedItems::class)->execute();

        $order->refresh();

        self::assertSame(OrderStatus::Refunded, $order->status);
        self::assertTrue($order->status->isFinal(), 'Заказ не дошёл до конечного состояния.');

        // Деньги сошлись: за невыданное возвращено всё.
        /** @var int|string|null $prepayment */
        $prepayment = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.order_id', $order->id)
            ->where('a.code', 'customer_prepayment')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS balance")
            ->value('balance');

        self::assertSame(0, (int) $prepayment, 'Деньги за невыданный товар зависли.');

        // И расхождение с поставщиком осталось видимым: за отвергнутые коды
        // ему, возможно, заплачено, и это предстоит разобрать.
        self::assertGreaterThan(
            0,
            DB::table('reconciliation_findings')->where('kind', 'supplier_returned_foreign_code')->count(),
        );
    }

    private function paidOrder(): Order
    {
        $order = $this->makeOrder(self::SKU);
        $eventId = 'evt_dishonest_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
