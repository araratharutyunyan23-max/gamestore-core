<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Отложенность триггера баланса — на НАСТОЯЩЕМ коммите.
 *
 * Пробел, найденный при финальной сверке. Баланс журнала проверяется и в
 * обычных тестах, но там ограничение переводится в IMMEDIATE: под
 * RefreshDatabase транзакция никогда не коммитится, и отложенный триггер не
 * сработал бы вовсе. То есть проверялась сама функция, но не то свойство,
 * ради которого она объявлена CONSTRAINT TRIGGER ... INITIALLY DEFERRED.
 *
 * А свойство это главное. Проводка попадает в базу двумя строками, и в
 * середине операции журнал ЗАКОНОМЕРНО не сходится. Немедленная проверка
 * запретила бы записывать проводки вообще; отложенная позволяет собрать
 * проводку целиком и требует схождения ровно один раз — на коммите.
 *
 * Здесь нет RefreshDatabase, поэтому коммит настоящий.
 */
final class DeferredLedgerTriggerTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function an_unbalanced_pair_is_rejected_at_commit_time(): void
    {
        $failure = null;

        try {
            DB::transaction(function (): void {
                $id = $this->transaction('unbalanced-'.uniqid());

                // Дебет 1000, кредит 999. Внутри транзакции это проходит:
                // ограничение отложено, и до коммита никто не возражает.
                $this->entry($id, LedgerAccount::CustomerPrepayment, LedgerDirection::Debit, 1000);
                $this->entry($id, LedgerAccount::Revenue, LedgerDirection::Credit, 999);

                // Доказательство отложенности: строки уже видны СВОЕЙ же
                // транзакции, то есть вставка состоялась и не была отбита.
                self::assertSame(2, DB::table('ledger_entries')->where('transaction_id', $id)->count());
            });
        } catch (Throwable $e) {
            $failure = $e;
        }

        // PDOException, а не QueryException — и это не придирка к типу.
        // Отложенное ограничение срабатывает на COMMIT, то есть вне
        // подготовленного запроса, и Laravel такую ошибку не заворачивает.
        // Практический вывод: код, ловящий QueryException вокруг проводки,
        // отложенный отказ НЕ поймает.
        self::assertInstanceOf(
            PDOException::class,
            $failure,
            'Несходящаяся проводка закоммитилась. Баланс журнала не обеспечен ничем.',
        );

        // Сообщение проверяется точно, а не по слову «ledger». Первый прогон
        // этого теста был зелёным из-за нарушения ПОСТОРОННЕГО ограничения
        // (недопустимый kind), в тексте которого тоже есть «ledger». Тест,
        // принимающий любую ошибку базы, доказывает только то, что база жива.
        self::assertStringContainsString('unbalanced by', $failure->getMessage());

        // И ничего не осталось: откат снял обе строки.
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    #[Test]
    public function a_balanced_pair_commits_normally(): void
    {
        // Обратная сторона: ограничение обязано пропускать корректную проводку,
        // иначе «защита» — это просто запрет работать.
        DB::transaction(function (): void {
            $id = $this->transaction('balanced-'.uniqid());

            $this->entry($id, LedgerAccount::CustomerPrepayment, LedgerDirection::Debit, 129000);
            $this->entry($id, LedgerAccount::Revenue, LedgerDirection::Credit, 129000);
        });

        self::assertSame(2, DB::table('ledger_entries')->count());
    }

    #[Test]
    public function a_single_sided_entry_never_survives_a_commit(): void
    {
        // Самый вероятный дефект в бою: разработчик забыл вторую половину
        // проводки. Одна строка — это деньги, появившиеся из ниоткуда.
        $failure = null;

        try {
            DB::transaction(function (): void {
                $id = $this->transaction('one-sided-'.uniqid());
                $this->entry($id, LedgerAccount::Revenue, LedgerDirection::Credit, 500);
            });
        } catch (Throwable $e) {
            $failure = $e;
        }

        self::assertInstanceOf(PDOException::class, $failure, 'Односторонняя проводка закоммитилась.');
        self::assertStringContainsString('double entry requires at least 2', $failure->getMessage());
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    private function transaction(string $idempotencyKey): int
    {
        /** @var int $id */
        $id = DB::table('ledger_transactions')->insertGetId([
            'kind' => LedgerTransactionKind::PaymentCaptured->value,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $id;
    }

    private function entry(int $transactionId, LedgerAccount $account, LedgerDirection $direction, int $amount): void
    {
        $accountId = DB::table('ledger_accounts')
            ->where('code', $account->value)
            ->where('currency', 'RUB')
            ->value('id');

        self::assertIsNumeric($accountId, "Счёт {$account->value} не заведён сидером.");

        DB::table('ledger_entries')->insert([
            'transaction_id' => $transactionId,
            'account_id' => (int) $accountId,
            'direction' => $direction->value,
            'amount_minor' => $amount,
            'currency' => 'RUB',
        ]);
    }
}
