<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\CodeDisposition;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Ledger\Enums\LedgerAccountKind;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ordering\Enums\IdempotencyState;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Enums\FindingSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Схема и код обязаны говорить одно и то же.
 *
 * Миграция — замороженный текст: список значений в CHECK намеренно НЕ
 * интерполируется из PHP-энума, иначе добавление кейса задним числом изменило бы
 * старую миграцию и `migrate:fresh` в CI дал бы схему, отличную от прода
 * (CLAUDE.md §3). Цена этого решения — риск расхождения, и закрывает его этот тест.
 *
 * Здесь же проверяются индексы, на которых держится «ровно один раз»: правило
 * проекта — такое утверждение обязано указывать имя индекса (CLAUDE.md §5.1).
 */
final class SchemaContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('checkConstraints')]
    public function enum_matches_database_check_constraint(string $constraint, array $expected): void
    {
        self::assertSame(
            $expected,
            $this->valuesInCheckConstraint($constraint),
            "Энум и ограничение {$constraint} разошлись. Правьте энум и добавляйте НОВУЮ миграцию, "
            .'а не старую: изменение применённой миграции даёт в CI схему, отличную от прода.',
        );
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function checkConstraints(): array
    {
        return [
            'статусы заказа' => ['orders_status_chk', OrderStatus::values()],
            'типы товаров' => ['products_type_chk', ProductType::values()],
            'режимы поставки' => ['products_supply_mode_chk', SupplyMode::values()],
            'статусы ключей' => ['license_keys_status_chk', LicenseKeyStatus::values()],
            'статусы платежа' => ['payment_events_status_chk', PaymentStatus::values()],
            'обработка события' => ['payment_events_process_state_chk', PaymentEventState::values()],
            'проекция оплаты' => ['order_payment_states_state_chk', PaymentProjectionState::values()],
            'исходы попыток' => ['delivery_attempts_outcome_chk', AttemptOutcome::values()],
            'поставщики' => ['delivery_attempts_supplier_chk', SupplierName::values()],
            'судьба кода' => ['supplier_issued_codes_disposition_chk', CodeDisposition::values()],
            'виды счетов' => ['ledger_accounts_kind_chk', LedgerAccountKind::values()],
            'направления проводки' => ['ledger_entries_direction_chk', LedgerDirection::values()],
            'виды транзакций' => ['ledger_transactions_kind_chk', LedgerTransactionKind::values()],
            'виды находок' => ['reconciliation_findings_kind_chk', FindingKind::values()],
            'severity находок' => ['reconciliation_findings_severity_chk', FindingSeverity::values()],
            'состояния ключа идемпотентности' => ['idempotency_keys_state_chk', IdempotencyState::values()],
        ];
    }

    #[Test]
    #[DataProvider('guaranteeIndexes')]
    public function guarantee_is_backed_by_an_index(string $index, string $guarantee): void
    {
        self::assertTrue(
            $this->indexExists($index),
            "Индекс {$index} отсутствует, значит гарантия «{$guarantee}» ничем не обеспечена.",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guaranteeIndexes(): array
    {
        return [
            'один заказ на ключ идемпотентности' => ['orders_idempotency_key_uq', 'повтор создания заказа не создаёт второй'],
            'одно событие на event_id' => ['payment_events_event_id_uq', 'повторный вебхук ничего не меняет'],
            'одна применённая оплата на заказ' => ['payment_events_one_applied_paid_uq', '50 вебхуков с разными event_id дают одну проводку'],
            'одна выдача на заказ' => ['deliveries_order_uq', 'товар выдаётся ровно один раз'],
            'один код в одни руки' => ['deliveries_code_hash_uq', 'один код не уйдёт в два заказа'],
            'один ключ на выдачу' => ['deliveries_license_key_uq', 'ключ из пула не уйдёт в два заказа'],
            'одна открытая попытка' => ['delivery_attempts_one_open_uq', 'таймаут не приводит к уходу на второго поставщика'],
            'один успех на заказ' => ['delivery_attempts_one_success_uq', 'повтор после таймаута не создаёт вторую выдачу'],
            'один вызов на request_id' => ['delivery_attempts_request_uq', 'один вызов учитывается один раз'],
            'один код на request_id' => ['supplier_issued_codes_request_uq', 'купленный код не теряется и не задваивается'],
            'одна проводка на пару' => ['ledger_entries_pair_uq', 'повторная проводка не удваивает остатки'],
            'одна денежная операция' => ['ledger_transactions_idempotency_uq', 'деньги не проводятся дважды'],
        ];
    }

    #[Test]
    public function final_order_statuses_cannot_be_left(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if (! $status->isFinal()) {
                continue;
            }

            self::assertSame(
                [],
                $status->allowedTargets(),
                "Из финального статуса {$status->value} не должно быть исходящих переходов.",
            );
        }
    }

    #[Test]
    public function every_non_final_status_has_a_way_forward(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status->isFinal()) {
                continue;
            }

            self::assertNotSame(
                [],
                $status->allowedTargets(),
                "Статус {$status->value} не финальный, но выхода из него нет — заказ зависнет навсегда.",
            );
        }
    }

    /**
     * Значения из `CHECK (col IN ('a','b'))` в порядке объявления.
     *
     * @return list<non-empty-string>
     */
    private function valuesInCheckConstraint(string $name): array
    {
        /** @var list<object{definition: string}> $rows */
        $rows = DB::select(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            [$name],
        );

        self::assertCount(1, $rows, "Ограничение {$name} не найдено в схеме.");

        preg_match_all("/'([^']+)'/", $rows[0]->definition, $matches);

        return $matches[1];
    }

    private function indexExists(string $name): bool
    {
        /** @var list<object{count: int}> $rows */
        $rows = DB::select('SELECT count(*)::int AS count FROM pg_indexes WHERE indexname = ?', [$name]);

        return $rows !== [] && $rows[0]->count === 1;
    }
}
