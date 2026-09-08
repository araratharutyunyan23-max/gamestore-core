<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\StateMachine\OrderItemStateMachine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\StructuredLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Возврат денег за позицию, которую выдать не удалось.
 *
 * Возврат — такая же операция, как выдача, и защищается так же. Вернуть деньги
 * дважды ровно настолько же плохо, как выдать код дважды: в первом случае
 * магазин теряет товар, во втором — деньги, и оба раза молча.
 *
 * Защиты две, и обе нужны.
 *
 * Первая — ключ идемпотентности проводки, построенный ПО ПОЗИЦИИ. Он физически
 * запрещает вторую проводку возврата, сколько бы раз сюда ни зашли и сколькими
 * бы воркерами одновременно.
 *
 * Вторая — условный переход статуса. Он гарантирует, что возврат стартует
 * только из состояния, где возвращать действительно есть за что: выданную
 * позицию машина состояний в refunded не пустит.
 *
 * Одной из них не хватило бы. Без ключа два воркера провели бы две проводки
 * (переход выиграл бы один, но деньги ушли бы дважды). Без перехода возврат
 * оставил бы позицию в прежнем статусе, и подметальщик пришёл бы за ней снова.
 */
final readonly class RefundOrderItem
{
    public function __construct(
        private ConnectionInterface $db,
        private OrderItemRepository $items,
        private OrderItemStateMachine $stateMachine,
        private LedgerRepository $ledger,
    ) {}

    /**
     * @return bool возврат проведён именно этим вызовом
     */
    public function execute(Order $order, OrderItem $stale): bool
    {
        try {
            $refunded = false;

            $this->db->transaction(function () use ($order, $stale, &$refunded): void {
                // Позиция перечитывается под блокировкой: решение принимается
                // по состоянию на момент операции, а не на момент выборки.
                $item = $this->items->lockById($stale->id);

                if ($item === null || ! $item->status->canTransitionTo(OrderItemStatus::Refunded)) {
                    return;
                }

                $this->ledger->post(
                    LedgerTransactionKind::ItemRefunded,
                    LedgerTransactionKind::ItemRefunded->idempotencyKeyFor($order->public_id.'#'.$item->line_no),
                    $order->id,
                    $item->currency,
                    [
                        // Обязательство перед покупателем не исчезает, а меняет
                        // природу: были должны товар — стали должны деньги.
                        ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Debit, 'amount' => $item->unit_amount_minor],
                        ['account' => LedgerAccount::RefundsPayable, 'direction' => LedgerDirection::Credit, 'amount' => $item->unit_amount_minor],
                    ],
                );

                $refunded = $this->stateMachine
                    ->tryTransition($item, OrderItemStatus::Refunded, reason: 'not_deliverable')
                    ->changedAnything();

                if ($refunded) {
                    StructuredLog::delivery(
                        'item_refunded',
                        $order->public_id.'#'.$item->line_no,
                        reason: (string) $item->unit_amount_minor,
                    );
                }
            });

            return $refunded;
        } catch (UniqueConstraintViolationException) {
            // Проводка возврата по этой позиции уже есть. Ловится СНАРУЖИ
            // транзакции: после 23505 транзакция PostgreSQL уже в состоянии
            // abort, и внутри неё делать нечего.
            //
            // Это не ошибка, а штатный исход повтора — ровно та причина,
            // по которой ключ идемпотентности и заведён.
            StructuredLog::delivery('item_refund_duplicate_prevented', $order->public_id);

            return false;
        }
    }
}
