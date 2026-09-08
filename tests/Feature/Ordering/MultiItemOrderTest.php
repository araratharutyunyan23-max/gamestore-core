<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Заказ из нескольких товаров — задача 1 второго этапа.
 *
 * Этот шаг ещё ничего не выдаёт и ничего не возвращает: он только вводит
 * позиции. Проверяется ровно одно — что модель заказа выдержала переход от
 * «один товар» к «списку товаров», а деньги при этом сошлись.
 */
final class MultiItemOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function it_creates_an_order_from_several_skus(): void
    {
        $response = $this->create(['KEY-CS2-PRIME', 'STEAM-TOPUP-500', 'SUB-DISCORD-1M'])
            ->assertCreated();

        $response->assertJsonPath('data.items.0.sku', 'KEY-CS2-PRIME')
            ->assertJsonPath('data.items.1.sku', 'STEAM-TOPUP-500')
            ->assertJsonPath('data.items.2.sku', 'SUB-DISCORD-1M');

        // Порядок позиций — тот, что прислал покупатель, а не тот, что вернула
        // база. Без явного порядка два одинаковых запроса дают разные ответы.
        $response->assertJsonPath('data.items.0.line_no', 1)
            ->assertJsonPath('data.items.2.line_no', 3);

        /** @var list<array{status: string}> $items */
        $items = $response->json('data.items');

        foreach ($items as $item) {
            self::assertSame(OrderItemStatus::Pending->value, $item['status']);
        }
    }

    #[Test]
    public function the_order_total_is_the_sum_of_its_items(): void
    {
        $skus = ['KEY-CS2-PRIME', 'STEAM-TOPUP-500', 'SUB-DISCORD-1M'];
        $expected = (int) Product::query()->whereIn('sku', $skus)->sum('price_minor');

        $this->create($skus)
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', $expected);
    }

    #[Test]
    public function the_same_sku_twice_is_two_separate_items(): void
    {
        // Два одинаковых товара в заказе — это два кода к выдаче, а не ошибка
        // ввода. Схлопнув их в одну позицию, мы выдали бы один код там, где
        // заплачено за два.
        $response = $this->create(['KEY-CS2-PRIME', 'KEY-CS2-PRIME'])->assertCreated();

        self::assertCount(2, (array) $response->json('data.items'));

        $unit = Product::query()->where('sku', 'KEY-CS2-PRIME')->firstOrFail()->price_minor;
        $response->assertJsonPath('data.amount_minor', $unit * 2);
    }

    #[Test]
    public function a_single_sku_body_still_works(): void
    {
        // Контракт первого этапа: по нему уже интегрировались, и он описан
        // в OpenAPI. Заказ из одного товара — частный случай списка, а не
        // отдельный путь; два пути разошлись бы в том переходе, который никто
        // не догадался проверить.
        $this->withHeader('Idempotency-Key', 'legacy-single')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.sku', 'KEY-CS2-PRIME')
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function it_refuses_an_empty_item_list(): void
    {
        // Заказ на ноль рублей нечего выдавать и не за что возвращать.
        $this->withHeader('Idempotency-Key', 'empty-items')
            ->postJson('/api/v1/orders', ['items' => []])
            ->assertStatus(422);
    }

    #[Test]
    public function it_refuses_both_forms_at_once(): void
    {
        // sku и items вместе — запрос с двумя разными смыслами. Угадывать
        // намерение клиента в платёжном пути нельзя.
        $this->withHeader('Idempotency-Key', 'both-forms')
            ->postJson('/api/v1/orders', ['sku' => 'KEY-EFT', 'items' => [['sku' => 'KEY-EFT']]])
            ->assertStatus(422);
    }

    #[Test]
    public function it_refuses_an_unreasonably_long_item_list(): void
    {
        // Без границы один запрос может попросить выдать тысячи кодов.
        $this->withHeader('Idempotency-Key', 'too-many')
            ->postJson('/api/v1/orders', ['items' => array_fill(0, 21, ['sku' => 'KEY-CS2-PRIME'])])
            ->assertStatus(422);
    }

    #[Test]
    public function an_unknown_sku_anywhere_in_the_list_rejects_the_whole_order(): void
    {
        // Частичное создание заказа хуже отказа: покупатель заплатил бы за то,
        // чего в заказе нет, и разбираться пришлось бы сверке.
        $this->create(['KEY-CS2-PRIME', 'NO-SUCH-SKU'])->assertStatus(422);

        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
    }

    #[Test]
    public function repeating_the_idempotency_key_does_not_duplicate_items(): void
    {
        $skus = ['KEY-CS2-PRIME', 'STEAM-TOPUP-500'];

        $first = $this->create($skus, 'repeat-multi')->assertCreated();
        $second = $this->create($skus, 'repeat-multi')->assertCreated();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(2, DB::table('order_items')->count());
    }

    /**
     * @param  list<string>  $skus
     * @return TestResponse<JsonResponse>
     */
    private function create(array $skus, string $key = 'multi-order'): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', [
            'items' => array_map(static fn (string $sku): array => ['sku' => $sku], $skus),
        ]);
    }
}
