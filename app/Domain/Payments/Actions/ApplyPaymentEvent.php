<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Payments\Enums\PaymentEventState;
use App\Domain\Payments\Enums\PaymentProjectionState;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Repositories\PaymentEventRepository;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Support\StructuredLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Применение платёжного события. Вся денежная логика — здесь, вне HTTP-запроса.
 *
 * Ключевых решений три.
 *
 * Первое: состояние оплаты живёт в отдельной проекции, а не в orders.status.
 * Поздний failed обязан стать правдой о деньгах, но не имеет права отменить
 * уже отданный клиенту код.
 *
 * Второе: признание оплаты идемпотентно по ЗАКАЗУ, а не по event_id. Контракт
 * обещает тот же event_id на повтор, но критерий приёмки допускает 50 вебхуков
 * с РАЗНЫМИ event_id. При ключе по событию журнал получил бы 50 проводок и
 * остался бы при этом идеально сбалансированным — то есть сломался бы незаметно.
 *
 * Третье: 23505 ловится СНАРУЖИ транзакции. После нарушения ограничения
 * транзакция PostgreSQL уже в состоянии abort, и любой следующий запрос в ней
 * вернёт 25P02 вместо осмысленной обработки.
 */
final readonly class ApplyPaymentEvent
{
    public function __construct(
        private ConnectionInterface $db,
        private PaymentEventRepository $events,
        private OrderRepository $orders,
        private OrderStateMachine $stateMachine,
        private LedgerRepository $ledger,
    ) {}

    /**
     * @throws \Throwable любая ошибка внутри транзакции применения платежа
     */
    public function execute(string $eventId): PaymentEventState
    {
        $event = $this->events->findByEventId($eventId);

        if ($event === null) {
            return PaymentEventState::Pending;
        }

        // Событие уже применено — повтор ничего не меняет. Это и есть
        // идемпотентность из контракта: at-least-once доставка вебхука штатна.
        if ($event->applied_at !== null) {
            return $event->process_state;
        }

        $order = $this->orders->findByPublicId($event->order_public_id);

        if ($order === null) {
            // Вебхук обогнал создание заказа. Штатный сценарий, не ошибка:
            // событие остаётся неприменённым и будет подобрано позже.
            StructuredLog::webhook('webhook_order_missing', $event->event_id, $event->order_public_id);

            return PaymentEventState::OrderMissing;
        }

        if ($event->status === PaymentStatus::Paid && $event->amount_minor !== $order->amount_minor) {
            // Расхождение суммы — не повод выдавать товар. Фиксируем и ждём разбора.
            $this->events->markProcessed($event, PaymentEventState::AmountMismatch);
            StructuredLog::payment('payment_amount_mismatch', $order->public_id, $order->status->value, $order->status->value);

            return PaymentEventState::AmountMismatch;
        }

        // ConnectionInterface::transaction типизирован как mixed, поэтому исход
        // не возвращается из замыкания, а присваивается: наружу mixed не выходит.
        $state = PaymentEventState::Pending;

        try {
            $this->db->transaction(function () use ($event, $order, &$state): void {
                $state = $this->apply($event, $order);
            });

            return $state;
        } catch (UniqueConstraintViolationException) {
            // По заказу уже применено одно paid-событие. Деньги могли реально
            // прийти дважды, поэтому это не «ничего не делаем», а отдельный
            // класс аномалии для сверки.
            $this->events->markProcessed($event, PaymentEventState::DuplicatePaid);
            StructuredLog::webhook('webhook_duplicate_paid', $event->event_id, $event->order_public_id);

            return PaymentEventState::DuplicatePaid;
        }
    }

    private function apply(PaymentEvent $event, Order $stale): PaymentEventState
    {
        // Заказ ПЕРЕЧИТЫВАЕТСЯ под блокировкой, а не берётся из значения,
        // прочитанного до транзакции. Иначе решение принимается по устаревшему
        // статусу: заказ, успевший стать delivered, получил бы сторно оплаты
        // вместо пометки late_payment_failure.
        $order = $this->orders->lockById($stale->id);

        if ($order === null) {
            return PaymentEventState::OrderMissing;
        }

        if (! $this->projectPaymentState($event, $order)) {
            // Событие старше уже применённого: отбрасываем, не трогая деньги.
            $this->events->markProcessed($event, PaymentEventState::Stale);

            return PaymentEventState::Stale;
        }

        $state = $event->status === PaymentStatus::Paid
            ? $this->applyPaid($event, $order)
            : $this->applyFailed($event, $order);

        $this->events->markProcessed($event, $state);

        return $state;
    }

    /**
     * Монотонный upsert проекции по кортежу (occurred_at, received_at, id).
     *
     * Именно он реализует требование «вебхуки могут прийти не по порядку»:
     * решает не порядок доставки, а метка времени события у платёжной системы.
     *
     * @return bool false, если событие устарело
     */
    private function projectPaymentState(PaymentEvent $event, Order $order): bool
    {
        $projected = $event->status === PaymentStatus::Paid
            ? PaymentProjectionState::Paid
            : PaymentProjectionState::Failed;

        $affected = $this->db->affectingStatement(<<<'SQL'
            INSERT INTO order_payment_states
                (order_id, state, occurred_at, received_at, event_row_id, last_event_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (order_id) DO UPDATE
               SET state = EXCLUDED.state,
                   occurred_at = EXCLUDED.occurred_at,
                   received_at = EXCLUDED.received_at,
                   event_row_id = EXCLUDED.event_row_id,
                   last_event_id = EXCLUDED.last_event_id,
                   updated_at = now()
             WHERE (order_payment_states.occurred_at,
                    order_payment_states.received_at,
                    order_payment_states.event_row_id)
                 < (EXCLUDED.occurred_at, EXCLUDED.received_at, EXCLUDED.event_row_id)
        SQL, [
            $order->id,
            $projected->value,
            $event->occurred_at ?? $event->received_at,
            $event->received_at,
            $event->id,
            $event->event_id,
        ]);

        return $affected === 1;
    }

    private function applyPaid(PaymentEvent $event, Order $order): PaymentEventState
    {
        $transition = $this->stateMachine->tryTransition(
            $order,
            OrderStatus::Paid,
            reason: 'payment_applied',
            traceId: StructuredLog::traceId(),
        );

        if (! $transition->changedAnything()) {
            // Заказ уже финален (отказ платежа, отмена) — трогать нечего.
            if ($order->status->isFinal()) {
                return PaymentEventState::IgnoredFinal;
            }

            // Заказ уже оплачен, а это ЕЩЁ ОДНО событие paid с другим event_id.
            // Контракт обещает повтор с тем же идентификатором, так что здесь
            // либо нарушение контракта, либо деньги реально пришли дважды.
            // Второй раз признавать оплату нельзя, но и терять факт нельзя:
            // помечаем отдельным классом, который заберёт сверка.
            //
            // Проводки при этом НЕ делаем сознательно: мы не знаем, пришли ли
            // деньги на самом деле, а выдумывать движение денег из
            // неоднозначного входа хуже, чем оставить его в очереди на разбор.
            return PaymentEventState::DuplicatePaid;
        }

        $this->ledger->post(
            LedgerTransactionKind::PaymentCaptured,
            LedgerTransactionKind::PaymentCaptured->idempotencyKeyFor($order->public_id),
            $order->id,
            $order->currency,
            [
                ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Debit, 'amount' => $order->amount_minor],
                ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Credit, 'amount' => $order->amount_minor],
            ],
        );

        StructuredLog::payment('payment_applied', $order->public_id, OrderStatus::Created->value, OrderStatus::Paid->value);

        return PaymentEventState::Applied;
    }

    private function applyFailed(PaymentEvent $event, Order $order): PaymentEventState
    {
        // Заказ ещё не оплачивался — обычный отказ платежа.
        if ($order->status === OrderStatus::Created) {
            $this->stateMachine->tryTransition($order, OrderStatus::PaymentFailed, reason: 'payment_failed');
            StructuredLog::payment('payment_failed', $order->public_id, OrderStatus::Created->value, OrderStatus::PaymentFailed->value);

            return PaymentEventState::Applied;
        }

        // Оплата отозвана уже ПОСЛЕ признания. Если выдача ещё не начиналась —
        // отменяем заказ и сторнируем деньги. Если началась — выдачу не трогаем,
        // но поднимаем флаг: разбирать это должен человек, а не автоматика.
        if ($order->status === OrderStatus::Paid) {
            $this->stateMachine->tryTransition($order, OrderStatus::Cancelled, reason: 'payment_revoked');

            $this->ledger->post(
                LedgerTransactionKind::PaymentReversed,
                LedgerTransactionKind::PaymentReversed->idempotencyKeyFor($order->public_id),
                $order->id,
                $order->currency,
                [
                    ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Debit, 'amount' => $order->amount_minor],
                    ['account' => LedgerAccount::GatewayReceivable, 'direction' => LedgerDirection::Credit, 'amount' => $order->amount_minor],
                ],
            );

            StructuredLog::payment('payment_revoked', $order->public_id, OrderStatus::Paid->value, OrderStatus::Cancelled->value);

            return PaymentEventState::Applied;
        }

        $this->db->table('orders')->where('id', $order->id)->update([
            'needs_review' => true,
            'review_reason' => 'late_payment_failure',
            'updated_at' => now(),
        ]);

        StructuredLog::payment('payment_late_failure', $order->public_id, $order->status->value, $order->status->value);

        return PaymentEventState::IgnoredFinal;
    }
}
