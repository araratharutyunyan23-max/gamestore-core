<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SchemaFixtures;
use Tests\TestCase;

/**
 * «Журнал, который всегда сходится» — как невозможность, а не как соглашение.
 *
 * Все записи идут в обход доменных сервисов, напрямую в БД: доказываем, что
 * защита живёт в схеме и её нельзя обойти ни из джобы, ни из psql, ни из миграции.
 */
final class LedgerInvariantTest extends TestCase
{
    use RefreshDatabase;
    use SchemaFixtures;

    private const AMOUNT = 129000;

    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->makeLedgerCheckImmediate();
    }

    #[Test]
    public function an_unbalanced_transaction_is_rejected(): void
    {
        $order = $this->createOrder();
        $txId = $this->captureTransactionFor($order->public_id, $order->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unbalanced by 1 minor units/');

        // Расхождение в одну копейку — и проводка не проходит.
        $this->postEntries($txId, [
            ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Debit, 'amount' => self::AMOUNT],
            ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Credit, 'amount' => self::AMOUNT - 1],
        ], $order->id);
    }

    #[Test]
    public function a_single_sided_transaction_is_rejected(): void
    {
        $order = $this->createOrder();
        $txId = $this->captureTransactionFor($order->public_id, $order->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/double entry requires at least 2/');

        // Одна половина проводки — не двойная запись, даже если её кто-то
        // «уравновесит» отдельным оператором позже.
        $this->postEntries($txId, [
            ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Debit, 'amount' => self::AMOUNT],
        ], $order->id);
    }

    #[Test]
    public function a_balanced_transaction_is_accepted(): void
    {
        $txId = $this->balancedTransaction();

        /** @var list<object{total: int|string}> $rows */
        $rows = DB::select(
            'SELECT SUM(amount_signed)::bigint AS total FROM ledger_entries WHERE transaction_id = ?',
            [$txId],
        );

        // Каст обязателен: SUM(bigint) в PostgreSQL — numeric, а PDO отдаёт его
        // строкой. Без явного (int) сравнение '0' !== 0 молча провалилось бы.
        self::assertSame(0, (int) $rows[0]->total);
        self::assertSame(2, DB::table('ledger_entries')->where('transaction_id', $txId)->count());
    }

    #[Test]
    public function ledger_entries_cannot_be_edited(): void
    {
        $txId = $this->balancedTransaction();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('ledger_entries')->where('transaction_id', $txId)->update(['amount_minor' => 1]);
    }

    #[Test]
    public function ledger_entries_cannot_be_deleted(): void
    {
        $txId = $this->balancedTransaction();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('ledger_entries')->where('transaction_id', $txId)->delete();
    }

    #[Test]
    public function posting_the_same_pair_twice_is_rejected(): void
    {
        $txId = $this->balancedTransaction();

        // Повтор той же пары удвоил бы остатки, оставаясь идеально
        // сбалансированным: проверка суммы этого не ловит по построению.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->postEntries($txId, [
            ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Debit, 'amount' => self::AMOUNT],
            ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Credit, 'amount' => self::AMOUNT],
        ]);
    }

    #[Test]
    public function the_same_money_operation_cannot_be_recorded_twice(): void
    {
        $order = $this->createOrder();
        $this->captureTransactionFor($order->public_id, $order->id);

        // Ключ идемпотентности строится по ЗАКАЗУ, а не по event_id: два разных
        // события со status=paid по одному заказу не имеют права провести деньги
        // дважды. Это ровно приёмочный сценарий с 50 разными event_id.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->captureTransactionFor($order->public_id, $order->id);
    }

    private function captureTransactionFor(string $publicId, int $orderId): int
    {
        return $this->createLedgerTransaction(
            LedgerTransactionKind::PaymentCaptured,
            LedgerTransactionKind::PaymentCaptured->idempotencyKeyFor($publicId),
            $orderId,
        );
    }

    private function balancedTransaction(): int
    {
        $order = $this->createOrder(sprintf('ord_%05d', ++$this->orderSequence));
        $txId = $this->captureTransactionFor($order->public_id, $order->id);

        $this->postEntries($txId, [
            ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Debit, 'amount' => self::AMOUNT],
            ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Credit, 'amount' => self::AMOUNT],
        ], $order->id);

        return $txId;
    }
}
