<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Catalog\Enums\SupplyMode;
use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\DeliveryTarget;
use App\Domain\Delivery\Enums\CodeDisposition;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryRepository;
use App\Domain\Delivery\Repositories\LicenseKeyRepository;
use App\Domain\Delivery\Repositories\SupplierCodeRepository;
use App\Domain\Ledger\Enums\LedgerAccount;
use App\Domain\Ledger\Enums\LedgerDirection;
use App\Domain\Ledger\Enums\LedgerTransactionKind;
use App\Domain\Ledger\Repositories\LedgerRepository;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Ordering\StateMachine\OrderItemStateMachine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Cfg;
use App\Support\StructuredLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Выдача ОДНОЙ позиции заказа.
 *
 * Вся механика первого этапа сохранена дословно, только единицей стала позиция,
 * а не заказ. Это не косметика: аренда, эпоха и «ровно одна выдача» обязаны
 * быть на позиции, иначе заказ из трёх товаров выдаётся последовательно и
 * один залипший поставщик держит остальные два.
 *
 * Два места, на которых всё держится, — те же, что и были.
 *
 * Первое: перед выдачей читается ПРОЕКЦИЯ ПЛАТЕЖА, а не статус заказа. Поздний
 * failed, пришедший между признанием оплаты и стартом выдачи, иначе отдаёт
 * товар бесплатно и молча.
 *
 * Второе: нарушение deliveries_order_item_uq означает «эта позиция уже выдана»,
 * а не сбой. Проигравший гонку воркер обязан тихо выйти и НЕ трогать статус:
 * перевод позиции в delivery_failed на этом месте откатывал бы успешную выдачу.
 */
final readonly class DeliverOrderItem
{
    public function __construct(
        private ConnectionInterface $db,
        private OrderItemRepository $items,
        private OrderItemStateMachine $stateMachine,
        private DeliveryRepository $deliveries,
        private LicenseKeyRepository $keys,
        private LedgerRepository $ledger,
        private DeliverViaSupplier $supplier,
        private SupplierCodeRepository $codes,
    ) {}

    /**
     * @throws \Throwable любая ошибка внутри транзакции доставки
     */
    public function execute(Order $order, OrderItem $item): DeliveryOutcome
    {
        if (! $item->status->awaitsDelivery()) {
            return DeliveryOutcome::NotDeliverable;
        }

        $target = DeliveryTarget::from($order, $item);

        // Взаимное исключение — аренда, а не статус. По статусу невозможно
        // отличить «я начал выдачу» от «выдачу уже ведёт другой воркер»:
        // переход delivering -> delivering машина состояний не разрешает,
        // и CAS вернул бы false в обоих случаях.
        $lease = $this->items->acquireLease(
            $item->id,
            // UUID, а не ULID: колонка lease_token объявлена как uuid,
            // и тип в схеме первичен по отношению к предпочтениям в коде.
            (string) Str::uuid(),
            Cfg::leaseSeconds(),
            gethostname().':'.getmypid(),
        );

        if ($lease === null) {
            StructuredLog::delivery('delivery_lease_denied', $target->reference());

            return DeliveryOutcome::AlreadyInProgress;
        }

        // Захват аренды логируется наравне с отказом. Видеть только отказы —
        // значит не отличить «выдача идёт» от «выдача не начиналась», а это
        // первый вопрос при разборе застрявшей позиции.
        StructuredLog::delivery('delivery_lease_acquired', $target->reference());

        $wasStuck = $item->status === OrderItemStatus::OutOfStock
            || $item->status === OrderItemStatus::DeliveryFailed;

        // ConnectionInterface::transaction типизирован как mixed, поэтому исход
        // не возвращается из замыкания, а присваивается: наружу mixed не выходит.
        $outcome = DeliveryOutcome::NotDeliverable;

        try {
            // Для товара от поставщика порядок принципиально другой: сетевой
            // вызов обязан идти ВНЕ транзакции, поэтому весь путь целиком
            // в transaction() не заворачивается.
            if ($item->product->supply_mode === SupplyMode::Supplier) {
                $outcome = $this->deliverFromSupplier($order, $item, $target);
            } else {
                $this->db->transaction(function () use ($order, $item, $target, &$outcome): void {
                    $outcome = $this->deliverFromPool($order, $item, $target);
                });
            }

            if ($wasStuck && $outcome === DeliveryOutcome::Delivered) {
                StructuredLog::delivery('order_recovered', $target->reference(), reason: 'redelivered_after_failure');
            }

            return $outcome;
        } catch (UniqueConstraintViolationException) {
            // Конкурент выдал первым. Это успех соседа, а не наша ошибка:
            // статус не трогаем, ничего не откатываем.
            StructuredLog::delivery('delivery_duplicate_prevented', $target->reference());

            return DeliveryOutcome::AlreadyDelivered;
        } finally {
            // Аренда снимается всегда: иначе упавшая выдача держала бы позицию
            // до истечения таймаута вместо немедленного повтора.
            $this->items->releaseLease($lease);
        }
    }

    private function deliverFromPool(Order $order, OrderItem $stale, DeliveryTarget $target): DeliveryOutcome
    {
        // Проверки выше делались ДО транзакции и к этому моменту могли устареть.
        // Позиция перечитывается под блокировкой, иначе поздний failed, успевший
        // отменить заказ, всё равно получит код и выручку.
        $item = $this->items->lockById($stale->id);

        if ($item === null || ! $item->status->awaitsDelivery()) {
            return DeliveryOutcome::NotDeliverable;
        }

        if ($this->deliveries->findByOrderItemId($item->id) !== null) {
            return DeliveryOutcome::AlreadyDelivered;
        }

        // Позиция уже в delivering означает, что предыдущий воркер упал, не
        // закончив: его аренда протухла и досталась нам. Переход не нужен —
        // статус уже правильный, а исключительность даёт аренда.
        if ($item->status !== OrderItemStatus::Delivering
            && ! $this->stateMachine->tryTransition($item, OrderItemStatus::Delivering, reason: 'delivery_started')->changedAnything()) {
            return DeliveryOutcome::NotDeliverable;
        }

        $key = $this->keys->claimAvailable($item->product_id);

        if ($key === null) {
            // Пустой остаток — восстановимое состояние. Позиция ждёт пополнения
            // и доводится позже, а не падает с ошибкой.
            $this->stateMachine->tryTransition($item, OrderItemStatus::OutOfStock, reason: 'no_keys_available');
            StructuredLog::delivery('delivery_out_of_stock', $target->reference());

            return DeliveryOutcome::OutOfStock;
        }

        // Ключ занят под эту позицию и больше никому не достанется. Событие
        // отделено от order_delivered намеренно: между ними идёт проводка по
        // журналу, и если процесс упадёт посередине, по логу будет видно, что
        // ключ уже израсходован.
        StructuredLog::delivery('key_reserved', $target->reference(), $key->codeLast4);

        $deliveryId = $this->deliveries->recordFromPool($target, $key);

        $this->keys->markIssued($key->id, $deliveryId);
        $this->keys->decrementAvailable($item->product_id);
        $this->keys->commitIssuedCounters($item->product_id);

        $this->recordRevenue($order, $item);

        $this->stateMachine->tryTransition($item, OrderItemStatus::Delivered, reason: 'delivered_from_pool');

        StructuredLog::delivery('order_delivered', $target->reference(), $key->codeLast4);

        return DeliveryOutcome::Delivered;
    }

    /**
     * Выдача кодом внешнего поставщика.
     *
     * Транзакция открывается ДВАЖДЫ и обе — короткие: перевод в delivering до
     * вызова и фиксация результата после. Между ними сетевой вызов, во время
     * которого никаких блокировок мы не держим.
     */
    private function deliverFromSupplier(Order $order, OrderItem $item, DeliveryTarget $target): DeliveryOutcome
    {
        if ($item->status !== OrderItemStatus::Delivering
            && ! $this->stateMachine->tryTransition($item, OrderItemStatus::Delivering, reason: 'delivery_started')->changedAnything()) {
            return DeliveryOutcome::NotDeliverable;
        }

        $result = $this->supplier->execute($target);

        if ($result['outcome'] !== DeliveryOutcome::Delivered) {
            return $this->handleSupplierFailure($item, $target, $result['outcome']);
        }

        $captured = $this->codes->findByRequestId((string) $result['request_id']);

        if ($captured === null) {
            // Код получен, но не записан — такого быть не должно: запись идёт
            // отдельной микротранзакцией сразу после ответа поставщика.
            return DeliveryOutcome::NotDeliverable;
        }

        $outcome = DeliveryOutcome::NotDeliverable;

        $this->db->transaction(function () use ($order, $item, $target, $result, $captured, &$outcome): void {
            $this->deliveries->recordFromSupplier(
                $target,
                $result['supplier'] ?? SupplierName::primary(),
                (string) $result['request_id'],
                $captured->encryptedCode,
                $captured->codeHash,
                $captured->codeLast4,
            );

            $this->codes->assign($captured->id, CodeDisposition::ForOrder);
            $this->recordRevenue($order, $item);
            $this->stateMachine->tryTransition($item, OrderItemStatus::Delivered, reason: 'delivered_from_supplier');

            StructuredLog::delivery('order_delivered', $target->reference(), $captured->codeLast4);
            $outcome = DeliveryOutcome::Delivered;
        });

        return $outcome;
    }

    private function handleSupplierFailure(
        OrderItem $item,
        DeliveryTarget $target,
        DeliveryOutcome $outcome,
    ): DeliveryOutcome {
        // Неизвестность оставляет позицию в delivering: судьба кода выясняется
        // фоновым разрешением, и объявлять отказ сейчас означало бы разрешить
        // повторную покупку у другого поставщика.
        if ($outcome === DeliveryOutcome::AwaitingResolution) {
            StructuredLog::delivery('delivery_awaiting_resolution', $target->reference());

            return $outcome;
        }

        $this->stateMachine->tryTransition($item, OrderItemStatus::DeliveryFailed, reason: 'suppliers_exhausted');
        StructuredLog::delivery('delivery_failed', $target->reference(), reason: 'suppliers_exhausted');

        return DeliveryOutcome::DeliveryFailed;
    }

    /**
     * Выручка признаётся ПО ПОЗИЦИИ, а не по заказу.
     *
     * Раньше здесь проводилась сумма всего заказа. С многопозиционным заказом
     * это означало бы, что выдача одного товара из трёх закрывает предоплату
     * целиком — и заказ выглядел бы рассчитанным, хотя два товара ещё должны
     * покупателю.
     *
     * Ключ идемпотентности тоже стал позиционным: общий на заказ означал бы,
     * что вторая позиция не проведётся вовсе.
     */
    private function recordRevenue(Order $order, OrderItem $item): void
    {
        $this->ledger->post(
            LedgerTransactionKind::OrderDelivered,
            LedgerTransactionKind::OrderDelivered->idempotencyKeyFor($order->public_id.'#'.$item->line_no),
            $order->id,
            $item->currency,
            [
                ['account' => LedgerAccount::CustomerPrepayment, 'direction' => LedgerDirection::Debit, 'amount' => $item->unit_amount_minor],
                ['account' => LedgerAccount::Revenue, 'direction' => LedgerDirection::Credit, 'amount' => $item->unit_amount_minor],
            ],
        );
    }
}
