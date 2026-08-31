<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SchemaFixtures;
use Tests\TestCase;

/**
 * Жизненный цикл заказа и денормализация остатка.
 *
 * Ключевое здесь — необратимость финальных статусов. Без неё проигравший гонку
 * воркер, поймавший 23505, откатывает уже доставленный заказ в delivery_failed,
 * подметальщик снова идёт к поставщику, и сверка вечно показывает ложное
 * «оплачен, но не выдан» по фактически выданному заказу.
 */
final class OrderLifecycleInvariantTest extends TestCase
{
    use RefreshDatabase;
    use SchemaFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    #[DataProvider('finalStatuses')]
    public function a_final_order_cannot_change_status(string $final): void
    {
        $order = $this->createOrder();
        $this->forceStatus($order->id, $final);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/is final/');

        $this->forceStatus($order->id, OrderStatus::DeliveryFailed->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finalStatuses(): array
    {
        return [
            'доставлен' => [OrderStatus::Delivered->value],
            'оплата не прошла' => [OrderStatus::PaymentFailed->value],
            'отменён' => [OrderStatus::Cancelled->value],
        ];
    }

    #[Test]
    public function a_recoverable_order_can_still_move_forward(): void
    {
        $order = $this->createOrder();

        // out_of_stock — пауза, а не отказ: после пополнения остатка заказ обязан
        // дойти до выдачи (критерий приёмки №6).
        $this->forceStatus($order->id, OrderStatus::OutOfStock->value);
        $this->forceStatus($order->id, OrderStatus::Delivering->value);

        self::assertSame(OrderStatus::Delivering, $order->refresh()->status);
    }

    #[Test]
    public function restocking_a_sold_out_product_brings_it_back_to_the_showcase(): void
    {
        $product = Product::query()->where('sku', 'KEY-EFT')->firstOrFail();

        $this->setAvailableCount($product->id, 0);
        self::assertFalse($product->refresh()->in_stock, 'Распроданный товар обязан уйти с витрины.');

        $this->setAvailableCount($product->id, 5);
        self::assertTrue($product->refresh()->in_stock, 'После пополнения товар обязан вернуться на витрину.');
    }

    #[Test]
    public function a_supplier_product_stays_on_the_showcase_despite_a_zero_counter(): void
    {
        $product = Product::query()->where('sku', 'STEAM-TOPUP-500')->firstOrFail();

        self::assertSame(0, $product->stock?->available_count);

        // У товара от поставщика локального остатка нет по определению.
        // Наивное «in_stock = счётчик > 0» убрало бы половину каталога навсегда.
        self::assertTrue($product->in_stock);

        $this->setAvailableCount($product->id, 0);

        self::assertTrue($product->refresh()->in_stock, 'Касание счётчика не должно снимать supplier-товар с витрины.');
    }

    #[Test]
    public function every_product_gets_a_stock_row_automatically(): void
    {
        $product = Product::query()->create([
            'sku' => 'TEST-NEW-SKU',
            'name' => 'Новый товар',
            'type' => 'key',
            'price_minor' => 10000,
            'currency' => 'RUB',
            'supply_mode' => 'pool',
            'is_active' => true,
        ]);

        // Без строки остатка UPDATE счётчика молча задел бы ноль строк:
        // ключ ушёл бы клиенту, а витрина показывала бы фикцию.
        self::assertNotNull($product->refresh()->stock);
        self::assertSame(0, $product->stock?->available_count);
    }

    private function forceStatus(int $orderId, string $status): void
    {
        DB::table('orders')->where('id', $orderId)->update([
            'status' => $status,
            'status_changed_at' => now(),
        ]);
    }

    private function setAvailableCount(int $productId, int $count): void
    {
        DB::table('product_stock')->where('product_id', $productId)->update([
            'available_count' => $count,
            'updated_at' => now(),
        ]);
    }
}
