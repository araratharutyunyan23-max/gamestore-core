<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Repositories;

use App\Domain\Reconciliation\DTO\Anomaly;
use App\Domain\Reconciliation\Enums\FindingKind;
use Illuminate\Database\ConnectionInterface;

/**
 * Запросы-детекторы аномалий.
 *
 * Все они сырые и это оправдано: агрегация с условием по агрегату, кортежные
 * сравнения и CTE билдер не выражает, а именно от их точности зависит, будет
 * сверка говорить правду или шуметь.
 */
final readonly class AnomalyQueryRepository
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Оплачен, но не выдан.
     *
     * Возраст считается ПОСЛЕ агрегации, а не фильтром по строкам. Это не
     * придирка: фильтр `created_at < cutoff` внутри WHERE выбросил бы из суммы
     * дебет выдачи, если она случилась позже порога, — и каждый нормально
     * доставленный заказ помечался бы критической аномалией.
     *
     * @return list<Anomaly>
     */
    public function paidNotDelivered(int $olderThanMinutes): array
    {
        /** @var list<object{public_id: string, status: string, open_minor: int|string, opened_at: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT o.public_id,
                   o.status,
                   (-SUM(e.amount_signed))::bigint AS open_minor,
                   MIN(e.created_at) AS opened_at
              FROM ledger_entries e
              JOIN ledger_accounts a ON a.id = e.account_id
              JOIN orders o ON o.id = e.order_id
             WHERE a.code = 'customer_prepayment'
             GROUP BY o.id, o.public_id, o.status
            HAVING SUM(e.amount_signed) <> 0
               AND MIN(e.created_at) < now() - make_interval(mins => ?)
             ORDER BY MIN(e.created_at)
        SQL, [$olderThanMinutes]);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                // Ожидание пополнения склада — это не инцидент, а пауза.
                // Если пометить его critical, критерий приёмки №6 навсегда
                // покрасит систему в «нездорова».
                kind: $row->status === 'out_of_stock' ? FindingKind::AwaitingRestock : FindingKind::PaidNotDelivered,
                subject: $row->public_id,
                details: ['status' => $row->status, 'open_minor' => (int) $row->open_minor, 'opened_at' => $row->opened_at],
            ),
            $rows,
        );
    }

    /**
     * Выдан, но не оплачен — зеркальный случай и куда более дорогой:
     * товар ушёл бесплатно.
     *
     * @return list<Anomaly>
     */
    public function deliveredNotPaid(): array
    {
        /** @var list<object{public_id: string, delivered_at: ?string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT o.public_id, d.created_at AS delivered_at
              FROM deliveries d
              JOIN orders o ON o.id = d.order_id
              LEFT JOIN order_payment_states s ON s.order_id = o.id
             WHERE s.order_id IS NULL OR s.state <> 'paid'
             ORDER BY d.created_at
        SQL);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::DeliveredNotPaid,
                subject: $row->public_id,
                details: ['delivered_at' => $row->delivered_at],
            ),
            $rows,
        );
    }

    /**
     * Событие принято, но не применено дольше порога: потерянная задача
     * или вебхук, обогнавший создание заказа.
     *
     * @return list<Anomaly>
     */
    public function unappliedPayments(int $olderThanSeconds): array
    {
        /** @var list<object{event_id: string, order_public_id: string, order_exists: bool, received_at: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT pe.event_id,
                   pe.order_public_id,
                   EXISTS (SELECT 1 FROM orders o WHERE o.public_id = pe.order_public_id) AS order_exists,
                   pe.received_at
              FROM payment_events pe
             WHERE pe.applied_at IS NULL
               AND pe.received_at < now() - make_interval(secs => ?)
             ORDER BY pe.received_at
        SQL, [$olderThanSeconds]);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: $row->order_exists ? FindingKind::UnappliedPayment : FindingKind::OrphanEvent,
                subject: $row->event_id,
                details: ['order_id' => $row->order_public_id, 'received_at' => $row->received_at],
            ),
            $rows,
        );
    }

    /**
     * Обращение к поставщику с неизвестной судьбой. Пока оно открыто, по
     * заказу нельзя начать новое — значит заказ стоит.
     *
     * @return list<Anomaly>
     */
    public function unresolvedSupplierAttempts(int $olderThanMinutes): array
    {
        /** @var list<object{request_id: string, public_id: string, supplier: string, probe_count: int}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT da.request_id, o.public_id, da.supplier, da.probe_count
              FROM delivery_attempts da
              JOIN orders o ON o.id = da.order_id
             WHERE da.outcome IN ('in_flight', 'timeout', 'unknown')
               AND da.started_at < now() - make_interval(mins => ?)
             ORDER BY da.started_at
        SQL, [$olderThanMinutes]);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::AttemptUnknown,
                subject: $row->request_id,
                details: ['order_id' => $row->public_id, 'supplier' => $row->supplier, 'probes' => (int) $row->probe_count],
            ),
            $rows,
        );
    }

    /**
     * Журнал не сходится по заказу. Проверяется КАЖДАЯ денежная транзакция
     * отдельно, а не общая сумма: две ошибочные проводки в разные стороны
     * дают ноль и прячут ошибку.
     *
     * @return list<Anomaly>
     */
    public function unbalancedLedger(): array
    {
        /** @var list<object{transaction_id: int, kind: string, imbalance: int|string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT t.id AS transaction_id, t.kind, SUM(e.amount_signed)::bigint AS imbalance
              FROM ledger_transactions t
              JOIN ledger_entries e ON e.transaction_id = t.id
             GROUP BY t.id, t.kind
            HAVING SUM(e.amount_signed) <> 0
        SQL);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::LedgerUnbalanced,
                subject: 'tx:'.$row->transaction_id,
                details: ['kind' => $row->kind, 'imbalance_minor' => (int) $row->imbalance],
            ),
            $rows,
        );
    }

    /**
     * Счётчик остатка разошёлся с реальным числом ключей.
     *
     * Дрейф ловится сравнением с ИСТОЧНИКОМ ИСТИНЫ, а не проверкой самого
     * счётчика: дрейф живёт как раз в строках, счётчик которых не обновлялся.
     * LEFT JOIN нужен, чтобы поймать и отсутствие строки остатка вовсе.
     *
     * @return list<Anomaly>
     */
    public function stockDrift(): array
    {
        /** @var list<object{sku: string, counter: ?int, real_free: int}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT p.sku,
                   s.available_count AS counter,
                   count(k.id) FILTER (WHERE k.status = 'available')::int AS real_free
              FROM products p
              LEFT JOIN product_stock s ON s.product_id = p.id
              LEFT JOIN license_keys k ON k.product_id = p.id
             WHERE p.supply_mode = 'pool'
             GROUP BY p.id, p.sku, s.product_id, s.available_count
            HAVING s.product_id IS NULL
                OR s.available_count <> count(k.id) FILTER (WHERE k.status = 'available')
        SQL);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::StockDrift,
                subject: $row->sku,
                details: ['counter' => $row->counter, 'real_free' => (int) $row->real_free],
            ),
            $rows,
        );
    }

    /**
     * Один физический код в двух выдачах. Индекс это запрещает, но детектор
     * нужен: он доказывает, что запрет работает, а не предполагается.
     *
     * @return list<Anomaly>
     */
    public function duplicateCodes(): array
    {
        /** @var list<object{code_hash: string, times: int}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT code_hash, count(*)::int AS times
              FROM deliveries
             GROUP BY code_hash
            HAVING count(*) > 1
        SQL);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::DuplicateCode,
                subject: substr($row->code_hash, 0, 12),
                details: ['times' => (int) $row->times],
            ),
            $rows,
        );
    }

    /**
     * Сумма вебхука разошлась с ценой заказа — выдавать по такому нельзя.
     *
     * @return list<Anomaly>
     */
    public function amountMismatches(): array
    {
        /** @var list<object{event_id: string, order_public_id: string}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT event_id, order_public_id
              FROM payment_events
             WHERE process_state = 'amount_mismatch'
             ORDER BY received_at
        SQL);

        return array_map(
            static fn (object $row): Anomaly => new Anomaly(
                kind: FindingKind::AmountMismatch,
                subject: $row->event_id,
                details: ['order_id' => $row->order_public_id],
            ),
            $rows,
        );
    }
}
