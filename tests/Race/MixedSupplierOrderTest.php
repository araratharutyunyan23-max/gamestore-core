<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Заказ, позиции которого идут РАЗНЫМИ путями — гейт шага Ш2.
 *
 * Первая версия состязательных тестов многопозиционного заказа проверяла
 * только товары из собственного пула. Это оставляло непроверенным ровно то,
 * ради чего задача 1 и написана: «каждый приходит от своего поставщика».
 * Пул не ходит по сети вовсе, и на нём независимость позиций доказать нельзя.
 *
 * Здесь три позиции идут тремя разными путями одновременно: собственный пул,
 * внешний поставщик с товаром и внешний поставщик без товара. Проверяется,
 * что неудача одной позиции не отменяет остальных — требование ТЗ 1.2
 * «что смогли выдать, то остаётся у покупателя».
 */
final class MixedSupplierOrderTest extends SupplierTestCase
{
    use OrderFixtures;

    private const POOL_SKU = 'KEY-CS2-PRIME';

    private const SUPPLIED_SKU = 'STEAM-TOPUP-500';

    private const REFUSED_SKU = 'SUB-DISCORD-1M';

    #[Test]
    public function a_failed_item_does_not_hold_back_the_rest_of_the_order(): void
    {
        // Товар есть только у одного SKU. У второго его нет ни у A, ни у B,
        // то есть отказ будет ДОКАЗАННЫМ у обоих — самый тяжёлый исход,
        // который позиция может пережить, не создав неизвестности.
        $this->stock(SupplierName::A, self::SUPPLIED_SKU, 5);
        $this->stock(SupplierName::A, self::REFUSED_SKU, 0);
        $this->stock(SupplierName::B, self::SUPPLIED_SKU, 5);
        $this->stock(SupplierName::B, self::REFUSED_SKU, 0);

        $order = $this->paidOrder([self::POOL_SKU, self::REFUSED_SKU, self::SUPPLIED_SKU]);

        $outcome = app(DeliverOrder::class)->execute($order->public_id);

        // Исход заказа — самый тревожный из случившегося с позициями. Заказ,
        // где часть товара не выдана, объявлять выданным нельзя.
        self::assertSame(DeliveryOutcome::DeliveryFailed, $outcome);

        $statuses = $this->itemStatuses($order);

        // Вот оно, главное: три позиции пришли в ТРИ РАЗНЫХ состояния за один
        // проход. Пока выдача была одна на заказ, такое было невозможно —
        // первая же неудача становилась исходом всего заказа.
        self::assertSame(OrderItemStatus::Delivered->value, $statuses[1], 'Позиция из пула не выдана.');
        self::assertSame(OrderItemStatus::DeliveryFailed->value, $statuses[2], 'Отказ поставщика не зафиксирован.');
        self::assertSame(OrderItemStatus::Delivered->value, $statuses[3], 'Провал соседней позиции задержал выдачу.');

        // Две выдачи из трёх позиций, и обе — настоящие, с разными кодами.
        $deliveries = DB::table('deliveries')->where('order_id', $order->id)->get();
        self::assertCount(2, $deliveries);
        self::assertCount(2, $deliveries->pluck('code_hash')->unique());
        self::assertCount(2, $deliveries->pluck('order_item_id')->unique());

        // Статус заказа выведен из позиций: не всё выдано — значит не delivered.
        self::assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);
    }

    #[Test]
    public function each_supplier_item_gets_its_own_request_id(): void
    {
        // Две позиции одного заказа к ОДНОМУ поставщику. Пока номер позиции не
        // входил в request_id, они получали один идентификатор: поставщик счёл
        // бы второй запрос повтором первого и вернул тот же код — заплачено
        // за два товара, получен один.
        $this->stock(SupplierName::A, self::SUPPLIED_SKU, 5);

        $order = $this->paidOrder([self::SUPPLIED_SKU, self::SUPPLIED_SKU]);

        self::assertSame(DeliveryOutcome::Delivered, app(DeliverOrder::class)->execute($order->public_id));

        $requestIds = DB::table('delivery_attempts')
            ->where('order_id', $order->id)
            ->pluck('request_id');

        self::assertCount(2, $requestIds->unique(), 'Две позиции ушли к поставщику с одним request_id.');

        // И поставщик действительно выдал два разных кода, а не один дважды.
        self::assertSame(
            2,
            $this->issuedCount(SupplierName::A, $order->public_id),
            'Поставщик выдал не два кода на две оплаченные позиции.',
        );

        self::assertCount(
            2,
            DB::table('deliveries')->where('order_id', $order->id)->pluck('code_hash')->unique(),
        );
    }

    #[Test]
    public function an_unresolved_timeout_on_one_item_does_not_block_the_others(): void
    {
        // Самый неприятный случай задачи 1: у одной позиции судьба обращения
        // неизвестна. Уходить по ней ко второму поставщику нельзя, но и
        // держать из-за неё весь заказ — значит не выдать товар, который
        // лежит в собственном пуле и готов прямо сейчас.
        $this->stock(SupplierName::A, self::SUPPLIED_SKU, 5);
        $this->behaviour(SupplierName::A, 'timeout', times: 1);

        $order = $this->paidOrder([self::SUPPLIED_SKU, self::POOL_SKU]);

        app(DeliverOrder::class)->execute($order->public_id);

        $statuses = $this->itemStatuses($order);

        // Позиция с неизвестностью остаётся в выдаче — не провалена и не выдана.
        self::assertSame(OrderItemStatus::Delivering->value, $statuses[1]);

        // А соседняя выдана, хотя первая ещё висит.
        self::assertSame(OrderItemStatus::Delivered->value, $statuses[2], 'Неизвестность у соседа задержала выдачу из пула.');

        // Ко второму поставщику при неизвестности не ушли: это и есть запрет
        // на второй код, который первый этап закрывал для заказа, а второй
        // обязан сохранить для позиции.
        self::assertSame(
            0,
            DB::table('delivery_attempts')->where('order_id', $order->id)->where('supplier', 'B')->count(),
            'При неизвестном исходе ушли ко второму поставщику — это путь к двойной выдаче.',
        );
    }

    /**
     * Статусы позиций по номеру строки.
     *
     * @return array<int, string>
     */
    private function itemStatuses(Order $order): array
    {
        $rows = DB::table('order_items')
            ->where('order_id', $order->id)
            ->orderBy('line_no')
            ->get();

        $statuses = [];

        foreach ($rows as $row) {
            $statuses[(int) $row->line_no] = (string) $row->status;
        }

        return $statuses;
    }

    /**
     * @param  non-empty-list<string>  $skus
     */
    private function paidOrder(array $skus): Order
    {
        $order = $this->makeMultiOrder($skus);
        $eventId = 'evt_mixed_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
