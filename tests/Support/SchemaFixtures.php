<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Фикстуры для тестов схемы.
 *
 * Пишут через DB напрямую и мимо доменных сервисов намеренно: цель этих тестов —
 * доказать, что инвариант держит БД САМА, даже когда данные приходят в обход
 * приложения. Если бы фикстуры шли через сервис, тест доказывал бы аккуратность
 * сервиса, а не наличие ограничения.
 */
trait SchemaFixtures
{
    private function createOrder(string $publicId = 'ord_00001', string $sku = 'KEY-CS2-PRIME'): Order
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        return Order::query()->create([
            'public_id' => $publicId,
            'idempotency_key' => 'idem-'.$publicId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'amount_minor' => $product->price_minor,
            'currency' => $product->currency,
        ]);
    }

    private function accountId(LedgerAccount $account, string $currency = 'RUB'): int
    {
        // value() отдаёт mixed, и сузить его надо здесь же: наружу mixed не выходит
        // (CLAUDE.md §2.1). Аннотация @var над результатом query-билдера защитой
        // не является — PHPStan поверит ей на слово, а PDO вернёт строку.
        $id = DB::table('ledger_accounts')
            ->where('code', $account->value)
            ->where('currency', $currency)
            ->value('id');

        self::assertIsNumeric($id, "Счёт {$account->value} ({$currency}) не заведён сидером.");

        return (int) $id;
    }

    private function createLedgerTransaction(LedgerTransactionKind $kind, string $idempotencyKey, ?int $orderId = null): int
    {
        /** @var int $id */
        $id = DB::table('ledger_transactions')->insertGetId([
            'kind' => $kind->value,
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
        ]);

        return $id;
    }

    /**
     * Проводки вставляются ОДНИМ оператором, а не по строке.
     *
     * Это не оптимизация: AFTER ROW триггеры ставятся в очередь и выполняются
     * в конце оператора, поэтому пара строк проверяется как целое. Построчная
     * вставка при IMMEDIATE упала бы на первой же строке («меньше двух проводок»),
     * а в бою — на COMMIT, если разработчик забыл вторую половину проводки.
     *
     * @param  list<array{account: LedgerAccount, direction: LedgerDirection, amount: int}>  $lines
     */
    private function postEntries(int $transactionId, array $lines, ?int $orderId = null): void
    {
        DB::table('ledger_entries')->insert(array_map(
            fn (array $line): array => [
                'transaction_id' => $transactionId,
                'account_id' => $this->accountId($line['account']),
                'direction' => $line['direction']->value,
                'amount_minor' => $line['amount'],
                'currency' => 'RUB',
                'order_id' => $orderId,
            ],
            $lines,
        ));
    }

    /**
     * Отложенный триггер баланса срабатывает на COMMIT, а тестовая транзакция
     * RefreshDatabase никогда не коммитится — без перевода в IMMEDIATE проверка
     * не выполнилась бы вовсе, и тест «ловил бы» несуществующую защиту.
     */
    private function makeLedgerCheckImmediate(): void
    {
        DB::statement('SET CONSTRAINTS ledger_entries_balanced IMMEDIATE');
    }
}
