<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Models\LicenseKey;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SchemaFixtures;
use Tests\TestCase;

/**
 * Инварианты выдачи — то, на чём держатся критерии приёмки 1, 4 и 5.
 *
 * Каждая проверка отвечает на вопрос «а что физически мешает выдать дважды».
 * Ответ обязан быть именем индекса, а не намерением разработчика (CLAUDE.md §5.1).
 */
final class DeliveryInvariantTest extends TestCase
{
    use RefreshDatabase;
    use SchemaFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function an_order_cannot_receive_a_second_delivery(): void
    {
        $order = $this->createOrder();
        $keys = $this->availableKeys($order, 2);

        $this->recordDelivery($order, $keys[0]);

        // Это и есть ответ на «50 параллельных вебхуков → ровно одна выдача»:
        // проигравшие упрутся сюда, а не в проверку в коде.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->recordDelivery($order, $keys[1]);
    }

    #[Test]
    public function one_code_cannot_be_handed_to_two_orders(): void
    {
        $first = $this->createOrder('ord_00001');
        $second = $this->createOrder('ord_00002');
        $key = $this->availableKeys($first, 1)[0];

        $this->recordDelivery($first, $key);

        $this->expectException(UniqueConstraintViolationException::class);

        // Тот же физический код, другой заказ — запрещено отпечатком, а не ключом:
        // шифртекст недетерминирован и уникальным индексом не защищается.
        $this->recordDelivery($second, $key);
    }

    #[Test]
    public function a_second_open_attempt_cannot_be_started_while_the_first_is_unresolved(): void
    {
        $order = $this->createOrder();

        // Поставщик A ответил таймаутом: судьба кода неизвестна.
        $this->recordAttempt($order, SupplierName::A, AttemptOutcome::Timeout);

        // Это ядро ловушки таймаута. Пока по A не доказано «не выдано»,
        // открыть попытку к B физически нельзя — иначе клиент получит два кода,
        // а мы заплатим двум поставщикам.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->recordAttempt($order, SupplierName::B, AttemptOutcome::InFlight);
    }

    #[Test]
    public function fallback_becomes_possible_only_after_the_first_attempt_is_sealed(): void
    {
        $order = $this->createOrder();
        $this->recordAttempt($order, SupplierName::A, AttemptOutcome::Timeout);

        // seal — обязательство поставщика НИКОГДА не выдавать код по этому
        // request_id. Только оно (или доказанный отказ) снимает замок.
        DB::table('delivery_attempts')
            ->where('supplier', SupplierName::A->value)
            ->update(['outcome' => AttemptOutcome::Sealed->value, 'definitive' => true]);

        $this->recordAttempt($order, SupplierName::B, AttemptOutcome::InFlight);

        self::assertSame(2, DB::table('delivery_attempts')->where('order_id', $order->id)->count());
        self::assertSame(
            1,
            DB::table('delivery_attempts')->where('order_id', $order->id)->where('outcome', AttemptOutcome::InFlight->value)->count(),
            'Открытая попытка обязана остаться ровно одна.',
        );
    }

    #[Test]
    public function an_order_cannot_have_two_successful_attempts(): void
    {
        $order = $this->createOrder();
        $this->recordAttempt($order, SupplierName::A, AttemptOutcome::Succeeded);

        // Даже если обе половины кода прошли по разным путям (retry после
        // таймаута и параллельный воркер) — успех может быть только один.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->recordAttempt($order, SupplierName::B, AttemptOutcome::Succeeded);
    }

    #[Test]
    public function the_same_request_id_cannot_be_recorded_twice(): void
    {
        $order = $this->createOrder();
        $this->recordAttempt($order, SupplierName::A, AttemptOutcome::Failed, epoch: 1);

        $this->expectException(UniqueConstraintViolationException::class);

        // Повтор после таймаута идёт с ТЕМ ЖЕ request_id — и не имеет права
        // создать вторую учётную запись о вызове.
        $this->recordAttempt($order, SupplierName::A, AttemptOutcome::Failed, epoch: 1);
    }

    #[Test]
    public function an_issued_key_cannot_be_moved_to_another_delivery(): void
    {
        $order = $this->createOrder();
        $key = $this->availableKeys($order, 1)[0];
        $deliveryId = $this->recordDelivery($order, $key);

        DB::table('license_keys')->where('id', $key->id)->update([
            'status' => LicenseKeyStatus::Issued->value,
            'delivery_id' => $deliveryId,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/already issued to delivery/');

        DB::table('license_keys')->where('id', $key->id)->update(['delivery_id' => $deliveryId + 1]);
    }

    /**
     * @return list<LicenseKey>
     */
    private function availableKeys(Order $order, int $count): array
    {
        $keys = array_values(
            LicenseKey::query()
                ->where('product_id', $order->product_id)
                ->available()
                ->orderBy('id')
                ->limit($count)
                ->get()
                ->all(),
        );

        self::assertCount($count, $keys, 'В пуле недостаточно свободных ключей для теста.');

        return $keys;
    }

    private function recordDelivery(Order $order, LicenseKey $key): int
    {
        /** @var int $id */
        $id = DB::table('deliveries')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'supply_mode' => 'pool',
            'license_key_id' => $key->id,
            'code_encrypted' => 'ciphertext',
            'code_hash' => $key->code_hash,
            'code_last4' => $key->code_last4,
        ]);

        return $id;
    }

    private function recordAttempt(Order $order, SupplierName $supplier, AttemptOutcome $outcome, int $epoch = 1): void
    {
        DB::table('delivery_attempts')->insert([
            'order_id' => $order->id,
            'supplier' => $supplier->value,
            'request_id' => sprintf('req_%s-%s-%d', $order->public_id, $supplier->value, $epoch),
            'epoch' => $epoch,
            'outcome' => $outcome->value,
        ]);
    }
}
