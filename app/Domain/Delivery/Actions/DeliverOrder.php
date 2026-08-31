<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\Enums\CodeDisposition;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryRepository;
use App\Domain\Delivery\Repositories\LicenseKeyRepository;
use App\Domain\Delivery\Repositories\SupplierCodeRepository;
use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Repositories\ReconciliationFindingRepository;
use App\Models\Order;
use App\Support\Cfg;
use App\Support\StructuredLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Выдача товара из собственного пула ключей.
 *
 * Сетевого вызова здесь нет, поэтому весь путь укладывается в одну транзакцию.
 * Для товаров от внешнего поставщика порядок принципиально другой (запись
 * намерения до вызова, вызов вне транзакции, отдельная фиксация) — это шаг Ш4.
 *
 * Два места, на которых всё держится.
 *
 * Первое: перед выдачей читается ПРОЕКЦИЯ ПЛАТЕЖА, а не статус заказа. Поздний
 * failed, пришедший между признанием оплаты и стартом выдачи, иначе отдаёт
 * товар бесплатно и молча — статус заказа к этому моменту уже 'paid', и ни одна
 * проверка по статусу этого не поймает.
 *
 * Второе: нарушение deliveries_order_uq означает «уже выдано», а не сбой.
 * Проигравший гонку воркер обязан вернуть существующий код и НЕ трогать статус:
 * перевод заказа в delivery_failed на этом месте откатывал бы успешную выдачу.
 */
final readonly class DeliverOrder
{
    public function __construct(
        private ConnectionInterface $db,
        private OrderRepository $orders,
        private OrderStateMachine $stateMachine,
        private DeliveryRepository $deliveries,
        private LicenseKeyRepository $keys,
        private LedgerRepository $ledger,
        private DeliverViaSupplier $supplier,
        private SupplierCodeRepository $codes,
        private ReconciliationFindingRepository $findings,
    ) {}

    /**
     * @throws \Throwable любая ошибка внутри транзакции доставки
     */
    public function execute(string $publicId): DeliveryOutcome
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            return DeliveryOutcome::NotDeliverable;
        }

        if ($order->delivery !== null) {
            return DeliveryOutcome::AlreadyDelivered;
        }

        if (! $order->status->awaitsDelivery()) {
            return DeliveryOutcome::NotDeliverable;
        }

        if ($order->paymentState?->state !== PaymentProjectionState::Paid) {
            $this->flagPaymentRevoked($order);

            return DeliveryOutcome::PaymentNotConfirmed;
        }

        // Статус на входе запоминается до захвата аренды: по нему видно, был
        // ли это обычный путь или доводка уже провалившегося заказа. Иначе
        // «восстановлен» неотличим от «выдан с первого раза», и по логам
        // нельзя посчитать, сколько заказов система вытащила сама.
        $wasStuck = $order->status->isRecoverableDeadEnd();

        // Взаимное исключение — аренда, а не статус. По статусу невозможно
        // отличить «я начал выдачу» от «выдачу уже ведёт другой воркер»:
        // переход delivering -> delivering машина состояний не разрешает,
        // и CAS вернул бы false в обоих случаях.
        $lease = $this->orders->acquireDeliveryLease(
            $order->id,
            // UUID, а не ULID: колонка lease_token объявлена как uuid,
            // и тип в схеме первичен по отношению к предпочтениям в коде.
            (string) Str::uuid(),
            Cfg::leaseSeconds(),
            gethostname().':'.getmypid(),
        );

        if ($lease === null) {
            StructuredLog::delivery('delivery_lease_denied', $order->public_id);

            return DeliveryOutcome::AlreadyInProgress;
        }

        // Захват аренды логируется наравне с отказом. Видеть только отказы —
        // значит не отличить «выдача идёт» от «выдача не начиналась», а это
        // первый вопрос при разборе застрявшего заказа.
        StructuredLog::delivery('delivery_lease_acquired', $order->public_id);

        // ConnectionInterface::transaction типизирован как mixed, поэтому исход
        // не возвращается из замыкания, а присваивается: наружу mixed не выходит.
        $outcome = DeliveryOutcome::NotDeliverable;

        try {
            // Для товара от поставщика порядок принципиально другой: сетевой
            // вызов обязан идти ВНЕ транзакции, поэтому весь путь целиком
            // в transaction() не заворачивается.
            if ($order->product->supply_mode === SupplyMode::Supplier) {
                $outcome = $this->deliverFromSupplier($order);

                if ($wasStuck && $outcome === DeliveryOutcome::Delivered) {
                    StructuredLog::delivery('order_recovered', $order->public_id, reason: 'redelivered_after_failure');
                }

                return $outcome;
            }

            $this->db->transaction(function () use ($order, &$outcome): void {
                $outcome = $this->deliverFromPool($order);
            });

            if ($wasStuck && $outcome === DeliveryOutcome::Delivered) {
                StructuredLog::delivery('order_recovered', $order->public_id, reason: 'redelivered_after_failure');
            }

            return $outcome;
        } catch (UniqueConstraintViolationException) {
            // Конкурент выдал первым. Это успех соседа, а не наша ошибка:
            // статус не трогаем, ничего не откатываем.
            StructuredLog::delivery('delivery_duplicate_prevented', $order->public_id);

            return DeliveryOutcome::AlreadyDelivered;
        } finally {
            // Аренда снимается всегда: иначе упавшая выдача держала бы заказ
            // до истечения таймаута вместо немедленного повтора.
            $this->orders->releaseDeliveryLease($lease);
        }
    }

    private function deliverFromPool(Order $stale): DeliveryOutcome
    {
        // Проверки выше делались ДО транзакции и к этому моменту могли устареть.
        // Заказ перечитывается под блокировкой, иначе поздний failed, успевший
        // отменить заказ, всё равно получит код и выручку.
        $order = $this->orders->lockById($stale->id);

        if ($order === null || ! $order->status->awaitsDelivery() || $order->delivery !== null) {
            return DeliveryOutcome::NotDeliverable;
        }

        if ($order->paymentState?->state !== PaymentProjectionState::Paid) {
            $this->flagPaymentRevoked($order);

            return DeliveryOutcome::PaymentNotConfirmed;
        }

        // Заказ уже в delivering означает, что предыдущий воркер упал, не
        // закончив: его аренда протухла и досталась нам. Переход не нужен —
        // статус уже правильный, а исключительность даёт аренда.
        if ($order->status !== OrderStatus::Delivering
            && ! $this->stateMachine->tryTransition($order, OrderStatus::Delivering, reason: 'delivery_started')->changedAnything()) {
            return DeliveryOutcome::NotDeliverable;
        }

        $key = $this->keys->claimAvailable($order->product_id);

        if ($key === null) {
            // Пустой остаток — восстановимое состояние. Заказ ждёт пополнения
            // и доводится позже, а не падает с ошибкой.
            $this->stateMachine->tryTransition($order, OrderStatus::OutOfStock, reason: 'no_keys_available');
            StructuredLog::delivery('delivery_out_of_stock', $order->public_id);

            return DeliveryOutcome::OutOfStock;
        }

        // Ключ занят под этот заказ и больше никому не достанется. Событие
        // отделено от order_delivered намеренно: между ними идёт проводка по
        // журналу, и если процесс упадёт посередине, по логу будет видно, что
        // ключ уже израсходован.
        StructuredLog::delivery('key_reserved', $order->public_id, $key->codeLast4);

        $deliveryId = $this->deliveries->recordFromPool($order, $key);

        $this->keys->markIssued($key->id, $deliveryId);
        $this->keys->decrementAvailable($order->product_id);
        $this->keys->commitIssuedCounters($order->product_id);

        // Выручка признаётся в момент выдачи: обязательство перед клиентом
        // закрывается тем же движением, которым появляется доход.
        $this->ledger->post(
            LedgerTransactionKind::OrderDelivered,
            LedgerTransactionKind::OrderDelivered->idempotencyKeyFor($order->public_id),
            $order->id,
            $order->currency,
            [
                ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Debit, 'amount' => $order->amount_minor],
                ['account' => LedgerAccount::Revenue, 'direction' => LedgerDirection::Credit, 'amount' => $order->amount_minor],
            ],
        );

        $this->stateMachine->tryTransition($order, OrderStatus::Delivered, reason: 'delivered_from_pool');

        StructuredLog::delivery('order_delivered', $order->public_id, $key->codeLast4);

        return DeliveryOutcome::Delivered;
    }

    /**
     * Выдача кодом внешнего поставщика.
     *
     * Транзакция открывается ДВАЖДЫ и обе — короткие: перевод в delivering до
     * вызова и фиксация результата после. Между ними сетевой вызов, во время
     * которого никаких блокировок мы не держим.
     */
    private function deliverFromSupplier(Order $order): DeliveryOutcome
    {
        if ($order->status !== OrderStatus::Delivering
            && ! $this->stateMachine->tryTransition($order, OrderStatus::Delivering, reason: 'delivery_started')->changedAnything()) {
            return DeliveryOutcome::NotDeliverable;
        }

        $result = $this->supplier->execute($order);

        if ($result['outcome'] !== DeliveryOutcome::Delivered) {
            return $this->handleSupplierFailure($order, $result['outcome']);
        }

        $captured = $this->codes->findByRequestId((string) $result['request_id']);

        if ($captured === null) {
            // Код получен, но не записан — такого быть не должно: запись идёт
            // отдельной микротранзакцией сразу после ответа поставщика.
            return DeliveryOutcome::NotDeliverable;
        }

        $outcome = DeliveryOutcome::NotDeliverable;

        $this->db->transaction(function () use ($order, $result, $captured, &$outcome): void {
            $deliveryId = $this->deliveries->recordFromSupplier(
                $order,
                $result['supplier'] ?? SupplierName::primary(),
                (string) $result['request_id'],
                $captured->encryptedCode,
                $captured->codeHash,
                $captured->codeLast4,
            );

            $this->codes->assign($captured->id, CodeDisposition::ForOrder);
            $this->recordRevenue($order);
            $this->stateMachine->tryTransition($order, OrderStatus::Delivered, reason: 'delivered_from_supplier');

            StructuredLog::delivery('order_delivered', $order->public_id, $captured->codeLast4);
            $outcome = DeliveryOutcome::Delivered;

            unset($deliveryId);
        });

        return $outcome;
    }

    private function handleSupplierFailure(Order $order, DeliveryOutcome $outcome): DeliveryOutcome
    {
        // Неизвестность оставляет заказ в delivering: судьба кода выясняется
        // фоновым разрешением, и объявлять отказ сейчас означало бы разрешить
        // повторную покупку у другого поставщика.
        if ($outcome === DeliveryOutcome::AwaitingResolution) {
            StructuredLog::delivery('delivery_awaiting_resolution', $order->public_id);

            return $outcome;
        }

        $this->stateMachine->tryTransition($order, OrderStatus::DeliveryFailed, reason: 'suppliers_exhausted');
        StructuredLog::delivery('delivery_failed', $order->public_id, reason: 'suppliers_exhausted');

        return DeliveryOutcome::DeliveryFailed;
    }

    private function recordRevenue(Order $order): void
    {
        $this->ledger->post(
            LedgerTransactionKind::OrderDelivered,
            LedgerTransactionKind::OrderDelivered->idempotencyKeyFor($order->public_id),
            $order->id,
            $order->currency,
            [
                ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Debit, 'amount' => $order->amount_minor],
                ['account' => LedgerAccount::Revenue, 'direction' => LedgerDirection::Credit, 'amount' => $order->amount_minor],
            ],
        );
    }

    private function flagPaymentRevoked(Order $order): void
    {
        $this->orders->flagForReview($order->id, 'payment_revoked_before_delivery');

        $this->findings->record(FindingKind::PaymentRevoked, $order->id, $order->public_id, [
            'projection' => $order->paymentState?->state->value,
        ]);

        StructuredLog::delivery('delivery_blocked', $order->public_id, reason: 'payment_not_confirmed');
    }
}
