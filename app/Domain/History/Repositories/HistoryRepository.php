<?php

declare(strict_types=1);

namespace App\Domain\History\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Чтение истории на момент времени.
 *
 * Ни один запрос здесь не смотрит на ТЕКУЩЕЕ состояние строк: состояние на
 * дату собирается только из журналов, которые нельзя переписать. Иначе ответ
 * зависел бы от того, что случилось после интересующего момента, — то есть
 * не был бы ответом о прошлом.
 */
final readonly class HistoryRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Статус заказа на момент — последний переход, случившийся не позже.
     *
     * Отсортировано по (времени, идентификатору): переходы одного заказа
     * умеют случаться в одну миллисекунду, и без второго ключа «последний»
     * определяется как повезёт.
     */
    public function orderStatusAsOf(int $orderId, CarbonImmutable $asOf): ?string
    {
        $status = $this->db->table('order_status_transitions')
            ->where('order_id', $orderId)
            ->where('created_at', '<=', $asOf)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('to_status');

        return is_string($status) ? $status : null;
    }

    /**
     * Статусы позиций заказа на момент — по одному запросу на весь заказ.
     *
     * DISTINCT ON — это ровно «последняя строка в каждой группе» средствами
     * PostgreSQL. Альтернатива, запрос на позицию, превращает чтение заказа
     * из десяти позиций в десять запросов (CLAUDE.md §4).
     *
     * @return array<int, string> номер строки => статус
     */
    public function itemStatusesAsOf(int $orderId, CarbonImmutable $asOf): array
    {
        /** @var list<object{line_no: int, to_status: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT DISTINCT ON (t.order_item_id) i.line_no, t.to_status
              FROM order_item_status_transitions t
              JOIN order_items i ON i.id = t.order_item_id
             WHERE t.order_id = ?
               AND t.created_at <= ?
             ORDER BY t.order_item_id, t.created_at DESC, t.id DESC
        SQL, [$orderId, $asOf]);

        $statuses = [];

        foreach ($rows as $row) {
            $statuses[(int) $row->line_no] = $row->to_status;
        }

        return $statuses;
    }

    /**
     * Сколько позиций заказа было выдано к моменту.
     */
    public function deliveredCountAsOf(int $orderId, CarbonImmutable $asOf): int
    {
        return $this->db->table('deliveries')
            ->where('order_id', $orderId)
            ->where('created_at', '<=', $asOf)
            ->count();
    }

    /**
     * Остатки счетов на момент, в копейках.
     *
     * Знак — «кредит минус дебет», как и везде в проекте: для обязательств
     * это долг, для доходов — заработанное.
     *
     * @return array<string, int>
     */
    public function accountBalancesAsOf(CarbonImmutable $asOf): array
    {
        /** @var list<object{code: string, balance: int|string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT a.code,
                   COALESCE(SUM(CASE WHEN e.direction = 'credit'
                                     THEN e.amount_minor
                                     ELSE -e.amount_minor END), 0) AS balance
              FROM ledger_entries e
              JOIN ledger_accounts a ON a.id = e.account_id
             WHERE e.created_at <= ?
             GROUP BY a.code
             ORDER BY a.code
        SQL, [$asOf]);

        $balances = [];

        foreach ($rows as $row) {
            $balances[$row->code] = (int) $row->balance;
        }

        return $balances;
    }

    /**
     * Обороты по счетам за период.
     *
     * Границы намеренно полуоткрытые: (from, to]. Иначе два соседних периода
     * пересекаются по одной секунде, и сумма периодов не сходится с остатком
     * на конец — а именно это сходство требует ТЗ 4.3.
     *
     * @return array<string, int>
     */
    public function accountTurnoverBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<object{code: string, turnover: int|string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT a.code,
                   COALESCE(SUM(CASE WHEN e.direction = 'credit'
                                     THEN e.amount_minor
                                     ELSE -e.amount_minor END), 0) AS turnover
              FROM ledger_entries e
              JOIN ledger_accounts a ON a.id = e.account_id
             WHERE e.created_at > ?
               AND e.created_at <= ?
             GROUP BY a.code
             ORDER BY a.code
        SQL, [$from, $to]);

        $turnover = [];

        foreach ($rows as $row) {
            $turnover[$row->code] = (int) $row->turnover;
        }

        return $turnover;
    }
}
