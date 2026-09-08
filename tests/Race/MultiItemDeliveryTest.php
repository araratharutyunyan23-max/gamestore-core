<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;

/**
 * Задача 1 второго этапа: заказ из нескольких товаров — на настоящей гонке.
 *
 * Тест написан ДО того, как индекс «одна выдача на заказ» уступил место
 * индексу «одна выдача на позицию». Порядок не случайный: между этими двумя
 * состояниями схемы есть промежуток, в котором «ровно одна выдача» не
 * обеспечено ничем, и заметить это можно только тестом, который уже стоит
 * на месте.
 *
 * Пятьдесят одновременных вебхуков — не имитация нагрузки. Очередь у тестового
 * сервера синхронная, поэтому каждый запрос внутри себя пытается признать
 * оплату и выдать ВСЕ позиции заказа. Пятьдесят таких попыток обязаны дать
 * ровно по одному коду на позицию.
 */
final class MultiItemDeliveryTest extends RaceTestCase
{
    use OrderFixtures;

    /** @var list<string> Товары из собственного пула: три разных ключа. */
    private const POOL_SKUS = ['KEY-CS2-PRIME', 'KEY-GTA5', 'GIFT-PSN-1000'];

    #[Test]
    public function every_item_of_a_multi_item_order_gets_its_own_code_exactly_once(): void
    {
        $order = $this->makeMultiOrder(self::POOL_SKUS);
        $payload = $this->webhookPayload($order, ['event_id' => 'evt_multi_pool']);

        $statuses = $this->fireParallel(array_fill(0, self::CONCURRENCY, $payload));

        // Ни одного ответа, кроме 200: любой другой код означает для платёжной
        // системы неудачную доставку и новую волну повторов.
        self::assertSame([200 => self::CONCURRENCY], $statuses);

        $this->assertEveryItemDeliveredExactlyOnce($order, count(self::POOL_SKUS));
    }

    #[Test]
    public function two_items_of_the_same_sku_get_two_different_codes(): void
    {
        // Самый коварный случай многопозиционного заказа: обе позиции просят
        // один товар. Заплачено за два кода, и выдать один — это недостача,
        // которую покупатель заметит раньше нас.
        $order = $this->makeMultiOrder(['KEY-CS2-PRIME', 'KEY-CS2-PRIME']);
        $payload = $this->webhookPayload($order, ['event_id' => 'evt_multi_same_sku']);

        $statuses = $this->fireParallel(array_fill(0, self::CONCURRENCY, $payload));

        self::assertSame([200 => self::CONCURRENCY], $statuses);
        $this->assertEveryItemDeliveredExactlyOnce($order, 2);
    }

    #[Test]
    public function the_money_of_a_fully_delivered_order_nets_to_zero(): void
    {
        // Инвариант задачи 1.3 в его простом виде: всё выдано, значит вся
        // предоплата закрыта выручкой. Частичный случай с возвратами — Ш3.
        $order = $this->makeMultiOrder(self::POOL_SKUS);

        $this->fireParallel([$this->webhookPayload($order, ['event_id' => 'evt_multi_money'])]);

        $this->assertEveryItemDeliveredExactlyOnce($order, count(self::POOL_SKUS));

        /** @var int|string|null $open */
        $open = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.order_id', $order->id)
            ->where('a.code', 'customer_prepayment')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS balance")
            ->value('balance');

        self::assertSame(
            0,
            (int) $open,
            'Заказ выдан целиком, а предоплата по нему не закрыта — деньги не сошлись.',
        );
    }

    /**
     * Каждая позиция выдана ровно один раз, и коды у всех разные.
     */
    private function assertEveryItemDeliveredExactlyOnce(Order $order, int $expectedItems): void
    {
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('line_no')->get();

        self::assertCount($expectedItems, $items, 'Позиций в заказе не столько, сколько заказано.');

        $deliveries = DB::table('deliveries')->where('order_id', $order->id)->get();

        self::assertCount(
            $expectedItems,
            $deliveries,
            'Выдач по заказу не столько, сколько позиций: часть товара не выдана либо выдана дважды.',
        );

        // Одна выдача на позицию — не «столько же строк», а именно взаимно
        // однозначное соответствие. Три выдачи по одной позиции дали бы тот
        // же счётчик и прошли бы более слабую проверку.
        $coveredItems = $deliveries->pluck('order_item_id')->filter()->unique();

        self::assertCount(
            $expectedItems,
            $coveredItems,
            'Выдачи не покрывают позиции один в один: какая-то позиция выдана дважды.',
        );

        // Коды разные. Один код на две позиции — это один код в двух руках,
        // ровно то, что задача 2 запрещает.
        self::assertCount(
            $expectedItems,
            $deliveries->pluck('code_hash')->unique(),
            'Один и тот же код выдан по нескольким позициям.',
        );

        foreach ($items as $item) {
            self::assertSame(
                OrderItemStatus::Delivered->value,
                $item->status,
                "Позиция {$item->line_no} осталась в статусе {$item->status}.",
            );
        }
    }
}
