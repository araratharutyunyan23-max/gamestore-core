<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Итог заказа равен сумме позиций — на НАСТОЯЩЕМ коммите.
 *
 * Тест живёт здесь, а не среди обычных, по той же причине, что и проверка
 * баланса журнала: ограничение отложенное, срабатывает на COMMIT, а под
 * RefreshDatabase транзакция не коммитится никогда. В обычном наборе такой
 * тест был бы зелёным, ничего не проверяя.
 *
 * И это не теория. Первая версия триггера была написана с ошибкой — обращалась
 * к полю, которого нет у таблицы orders, — и весь обычный набор остался
 * зелёным. Поймал её только состязательный.
 *
 * Отложенность здесь не украшение: позиции вставляются несколькими строками,
 * и в середине операции сумма закономерно не сходится. Немедленная проверка
 * запретила бы создавать заказ из двух товаров вообще.
 */
final class OrderTotalGuardTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function an_order_whose_items_do_not_add_up_is_rejected_at_commit(): void
    {
        $product = $this->product();

        $failure = $this->attempt(function () use ($product): void {
            $orderId = $this->insertOrder($product, total: $product->price_minor * 2);

            // Заплачено за два товара, положена одна позиция. Ровно тот случай,
            // когда покупатель платит за то, чего в заказе нет.
            $this->insertItem($orderId, $product, lineNo: 1);

            self::assertSame(1, DB::table('order_items')->where('order_id', $orderId)->count());
        });

        self::assertInstanceOf(PDOException::class, $failure, 'Заказ с несходящейся суммой закоммитился.');
        self::assertStringContainsString('items sum to', $failure->getMessage());
        self::assertSame(0, DB::table('orders')->count());
    }

    #[Test]
    public function an_order_without_any_items_is_rejected_at_commit(): void
    {
        // Самый дорогой случай: заказ, за который заплатят, и в котором нечего
        // выдавать. Поэтому триггер висит и на orders, а не только на позициях.
        $product = $this->product();

        $failure = $this->attempt(function () use ($product): void {
            $this->insertOrder($product, total: $product->price_minor);
        });

        self::assertInstanceOf(PDOException::class, $failure, 'Заказ без позиций закоммитился.');
        self::assertSame(0, DB::table('orders')->count());
    }

    #[Test]
    public function a_matching_order_commits_normally(): void
    {
        // Обратная сторона: ограничение обязано пропускать корректный заказ,
        // иначе «защита» — это просто запрет работать.
        $product = $this->product();

        DB::transaction(function () use ($product): void {
            $orderId = $this->insertOrder($product, total: $product->price_minor * 2);
            $this->insertItem($orderId, $product, lineNo: 1);
            $this->insertItem($orderId, $product, lineNo: 2);
        });

        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(2, DB::table('order_items')->count());
    }

    #[Test]
    public function removing_an_item_from_a_committed_order_is_rejected(): void
    {
        // Позицию нельзя тихо убрать из оплаченного заказа: деньги за неё уже
        // взяты, и её исчезновение — это недостача, которую никто не заметит.
        $product = $this->product();

        DB::transaction(function () use ($product): void {
            $orderId = $this->insertOrder($product, total: $product->price_minor * 2);
            $this->insertItem($orderId, $product, lineNo: 1);
            $this->insertItem($orderId, $product, lineNo: 2);
        });

        $failure = $this->attempt(function (): void {
            DB::table('order_items')->where('line_no', 2)->delete();
        });

        self::assertInstanceOf(PDOException::class, $failure, 'Позицию удалили, и заказ это пережил.');
        self::assertSame(2, DB::table('order_items')->count());
    }

    /**
     * Выполнить в транзакции и вернуть то, чем она упала.
     *
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

    private function product(): Product
    {
        return Product::query()->where('sku', 'KEY-CS2-PRIME')->firstOrFail();
    }

    private function insertOrder(Product $product, int $total): int
    {
        $suffix = uniqid();

        /** @var int $id */
        $id = DB::table('orders')->insertGetId([
            'public_id' => 'ord_t'.substr($suffix, -8),
            'idempotency_key' => 'guard-'.$suffix,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'amount_minor' => $total,
            'currency' => $product->currency,
        ]);

        return $id;
    }

    private function insertItem(int $orderId, Product $product, int $lineNo): void
    {
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'line_no' => $lineNo,
            'sku' => $product->sku,
            'unit_amount_minor' => $product->price_minor,
            'currency' => $product->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
