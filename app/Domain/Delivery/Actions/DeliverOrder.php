<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\Repositories\DeliveryRepository;
use App\Domain\Delivery\Repositories\LicenseKeyRepository;
use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Payments\Enums\PaymentProjectionState;
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

        if ($order->product->supply_mode === SupplyMode::Supplier) {
            return DeliveryOutcome::SupplierNotImplemented;
        }

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

        // ConnectionInterface::transaction типизирован как mixed, поэтому исход
        // не возвращается из замыкания, а присваивается: наружу mixed не выходит.
        $outcome = DeliveryOutcome::NotDeliverable;

        try {
            $this->db->transaction(function () use ($order, &$outcome): void {
                $outcome = $this->deliverFromPool($order);
            });

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

    private function flagPaymentRevoked(Order $order): void
    {
        $this->db->table('orders')->where('id', $order->id)->update([
            'needs_review' => true,
            'review_reason' => 'payment_revoked_before_delivery',
            'updated_at' => now(),
        ]);

        $this->db->table('reconciliation_findings')->insertOrIgnore([
            'kind' => 'payment_revoked',
            'severity' => 'critical',
            'order_id' => $order->id,
            'subject_ref' => $order->public_id,
            'details' => json_encode(
                ['projection' => $order->paymentState?->state->value],
                JSON_THROW_ON_ERROR,
            ),
            'detected_at' => now(),
        ]);

        StructuredLog::delivery('delivery_blocked', $order->public_id, reason: 'payment_not_confirmed');
    }
}
