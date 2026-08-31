<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Models\Product;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Захват ключа из пула под реальной конкуренцией.
 *
 * Тест намеренно НЕ использует RefreshDatabase: он оборачивает прогон в
 * транзакцию, и второе соединение просто не увидело бы фикстур — гонки
 * физически не существовало бы, а тест был бы зелёным и бессмысленным
 * (CLAUDE.md §6.3).
 *
 * Конкуренты сидят на РАЗНЫХ соединениях (pgsql и pgsql_rival). Внутри одного
 * PDO-коннекта блокировки строк не конфликтуют, поэтому «параллельный» тест на
 * одном соединении тоже ничего не доказывает.
 *
 * TRUNCATE между тестами безопасен: триггер неизменяемости журнала объявлен на
 * UPDATE и DELETE, но не на TRUNCATE.
 */
final class ConcurrentKeyClaimTest extends TestCase
{
    use DatabaseTruncation;

    private const CLAIM_SQL = <<<'SQL'
        UPDATE license_keys k
           SET status = 'reserved',
               reserved_at = now(),
               reserved_until = now() + interval '15 minutes'
         WHERE k.id = (
                 SELECT id FROM license_keys
                  WHERE product_id = ? AND status = 'available'
                  ORDER BY id
                  FOR UPDATE SKIP LOCKED
                  LIMIT 1)
           AND k.status = 'available'
        RETURNING k.id
    SQL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function two_concurrent_workers_never_take_the_same_key(): void
    {
        $productId = $this->productId('KEY-CS2-PRIME');

        $mine = DB::connection('pgsql');
        $rival = DB::connection('pgsql_rival');

        $mine->beginTransaction();
        $rival->beginTransaction();

        try {
            $first = $this->claim($mine, $productId);

            // Пока первая транзакция держит строку, второй воркер НЕ ждёт её:
            // SKIP LOCKED пропускает заблокированную строку и берёт следующую.
            // Без SKIP LOCKED здесь была бы очередь на одну и ту же первую строку.
            $second = $this->claim($rival, $productId);

            self::assertNotNull($first, 'Первый воркер обязан получить ключ.');
            self::assertNotNull($second, 'Второй воркер обязан получить ДРУГОЙ ключ, а не ждать первого.');
            self::assertNotSame($first, $second, 'Один ключ ушёл двум воркерам.');
        } finally {
            $mine->rollBack();
            $rival->rollBack();
        }
    }

    #[Test]
    public function the_last_key_goes_to_exactly_one_worker_and_the_other_gets_nothing(): void
    {
        // KEY-EFT засеян дефицитным намеренно; оставляем ровно один свободный ключ.
        $productId = $this->productId('KEY-EFT');
        $this->leaveExactlyOneAvailableKey($productId);

        $mine = DB::connection('pgsql');
        $rival = DB::connection('pgsql_rival');

        $mine->beginTransaction();
        $rival->beginTransaction();

        try {
            $winner = $this->claim($mine, $productId);
            $loser = $this->claim($rival, $productId);

            self::assertNotNull($winner, 'Последний ключ обязан достаться кому-то.');

            // Проигравший получает НОЛЬ СТРОК, а не исключение и не зависание.
            // Ноль строк — это out_of_stock: восстановимое состояние, из которого
            // заказ доводится после пополнения (критерий приёмки №6).
            self::assertNull($loser, 'Второй воркер обязан получить пустой результат, а не второй ключ.');
        } finally {
            $mine->rollBack();
            $rival->rollBack();
        }
    }

    #[Test]
    public function a_rolled_back_claim_returns_the_key_to_the_pool(): void
    {
        $productId = $this->productId('KEY-EFT');
        $this->leaveExactlyOneAvailableKey($productId);

        $mine = DB::connection('pgsql');
        $mine->beginTransaction();
        $claimed = $this->claim($mine, $productId);
        $mine->rollBack();

        self::assertNotNull($claimed);

        // Падение воркера до фиксации не имеет права съесть ключ: откат
        // транзакции возвращает его в пул целиком.
        $rival = DB::connection('pgsql_rival');
        $rival->beginTransaction();
        $again = $this->claim($rival, $productId);
        $rival->rollBack();

        self::assertSame($claimed, $again, 'После отката тот же ключ обязан снова стать свободным.');
    }

    private function claim(Connection $connection, int $productId): ?int
    {
        /** @var list<object{id: int}> $rows */
        $rows = $connection->select(self::CLAIM_SQL, [$productId]);

        return $rows === [] ? null : (int) $rows[0]->id;
    }

    private function productId(string $sku): int
    {
        return Product::query()->where('sku', $sku)->firstOrFail()->id;
    }

    private function leaveExactlyOneAvailableKey(int $productId): void
    {
        /** @var list<object{id: int}> $keep */
        $keep = DB::select(
            "SELECT id FROM license_keys WHERE product_id = ? AND status = 'available' ORDER BY id LIMIT 1",
            [$productId],
        );

        self::assertNotSame([], $keep);

        DB::table('license_keys')
            ->where('product_id', $productId)
            ->where('id', '!=', $keep[0]->id)
            ->update(['status' => 'reserved', 'reserved_until' => now()->addMinutes(15)]);
    }
}
