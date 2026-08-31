<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Repositories;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use Illuminate\Database\ConnectionInterface;

/**
 * Запись в денежный журнал.
 *
 * Обе половины проводки вставляются ОДНИМ оператором. Это не оптимизация:
 * AFTER ROW триггеры выполняются в конце оператора, поэтому пара проверяется
 * как целое. Построчная вставка означала бы, что забытая вторая половина
 * всплывёт только на COMMIT — в самом неудобном месте.
 */
final readonly class LedgerRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  list<array{account: LedgerAccount, direction: LedgerDirection, amount: int}>  $lines
     * @return bool всегда true; повторную операцию отсекает ledger_transactions_idempotency_uq
     */
    public function post(
        LedgerTransactionKind $kind,
        string $idempotencyKey,
        int $orderId,
        string $currency,
        array $lines,
    ): bool {
        $transactionId = $this->db->table('ledger_transactions')->insertGetId([
            'kind' => $kind->value,
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'created_at' => now(),
        ]);

        $accounts = $this->accountIds($currency);

        $this->db->table('ledger_entries')->insert(array_map(
            static fn (array $line): array => [
                'transaction_id' => $transactionId,
                'account_id' => $accounts[$line['account']->value],
                'direction' => $line['direction']->value,
                'amount_minor' => $line['amount'],
                'currency' => $currency,
                'order_id' => $orderId,
                'created_at' => now(),
            ],
            $lines,
        ));

        return true;
    }

    /**
     * Суммарный дисбаланс журнала. Ноль — необходимое условие здоровья,
     * но не достаточное: две ошибочные проводки в разные стороны тоже дают
     * ноль, поэтому есть отдельный детектор по каждой транзакции.
     */
    public function totalImbalanceMinor(): int
    {
        /** @var list<object{total: int|string|null}> $rows */
        $rows = $this->db->select('SELECT COALESCE(SUM(amount_signed), 0)::bigint AS total FROM ledger_entries');

        // Каст обязателен: SUM(bigint) в PostgreSQL возвращает numeric,
        // а PDO отдаёт numeric строкой.
        return (int) $rows[0]->total;
    }

    /**
     * Деньги за оплаченные, но ещё не выданные заказы.
     *
     * Это независимый от статусов заказа способ увидеть ту же картину:
     * остаток по обязательству перед клиентом обязан сходиться с суммой
     * заказов, которые оплачены и не доставлены.
     */
    public function openPrepaymentMinor(): int
    {
        /** @var list<object{total: int|string|null}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT COALESCE(-SUM(e.amount_signed), 0)::bigint AS total
              FROM ledger_entries e
              JOIN ledger_accounts a ON a.id = e.account_id
             WHERE a.code = 'customer_prepayment'
        SQL);

        return (int) $rows[0]->total;
    }

    /**
     * @return array<string, int>
     */
    private function accountIds(string $currency): array
    {
        /** @var list<object{code: string, id: int|string}> $rows */
        $rows = $this->db->select(
            'SELECT id, code FROM ledger_accounts WHERE currency = ?',
            [$currency],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[$row->code] = (int) $row->id;
        }

        return $map;
    }
}
