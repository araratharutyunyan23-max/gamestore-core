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
use App\Domain\Payments\Repositories\OrderPaymentStateRepository;
use App\Domain\Payments\Repositories\PaymentEventRepository;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Repositories\ReconciliationFindingRepository;
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
        private OrderPaymentStateRepository $paymentStates,
        private ReconciliationFindingRepository $findings,
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

        $projected = $event->status === PaymentStatus::Paid
            ? PaymentProjectionState::Paid
            : PaymentProjectionState::Failed;

        if (! $this->paymentStates->project($order->id, $event, $projected)) {
            // Событие старше уже применённого: отбрасываем, не трогая деньги.
            // В лог это попадает обязательно: устаревшие вебхуки — нормальная
            // работа сети, но их внезапный рост означает, что платёжная
            // система переигрывает очередь, и знать об этом надо раньше,
            // чем по расхождению в сверке.
            StructuredLog::webhook(
                'webhook_stale',
                $event->event_id,
                $event->order_public_id,
                reason: $projected->value,
            );

            $this->events->markProcessed($event, PaymentEventState::Stale);

            return PaymentEventState::Stale;
        }

        $state = $event->status === PaymentStatus::Paid
            ? $this->applyPaid($event, $order)
            : $this->applyFailed($event, $order);

        $this->events->markProcessed($event, $state);

        return $state;
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
            // Оплата поверх несостоявшегося платежа или отменённого заказа —
            // это аномалия другого рода, и разбирается она отдельно.
            if ($order->status === OrderStatus::PaymentFailed || $order->status === OrderStatus::Cancelled) {
                return PaymentEventState::IgnoredFinal;
            }

            // Во всех остальных случаях заказ уже оплачен — в том числе если он
            // успел стать delivered. Отнести такое событие к «заказ финален»
            // означало бы спрятать дубликат платежа от сверки: деньги могли
            // прийти дважды, а признак этого исчез бы.

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

        $this->orders->flagForReview($order->id, FindingKind::LatePaymentFailure->value);
        $this->findings->record(FindingKind::LatePaymentFailure, $order->id, $order->public_id);

        StructuredLog::payment('payment_late_failure', $order->public_id, $order->status->value, $order->status->value);

        return PaymentEventState::IgnoredFinal;
    }
}
