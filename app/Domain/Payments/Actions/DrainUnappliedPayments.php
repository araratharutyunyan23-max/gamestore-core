<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Repositories\PaymentEventRepository;
use App\Jobs\ApplyPaymentEventJob;
use App\Support\Cfg;
use App\Support\StructuredLog;

/**
 * Фоновая доводка неприменённых платёжных событий.
 *
 * Закрывает два разрыва, которые иначе теряют платёж навсегда.
 *
 * Первый: падение между COMMIT приёма события и постановкой задачи. Все
 * последующие повторы платёжной системы уйдут в ON CONFLICT DO NOTHING, и
 * задача не встанет уже никогда — событие останется лежать применённым нулю раз.
 *
 * Второй: вебхук, обогнавший создание заказа. Задача отработала, заказа не
 * нашла и завершилась; событие ждёт здесь, пока заказ появится.
 *
 * Идемпотентность обеспечивает сама задача, поэтому повторная постановка
 * безвредна: лучше поставить лишний раз, чем не поставить ни разу.
 */
final readonly class DrainUnappliedPayments
{
    public function __construct(private PaymentEventRepository $events) {}

    /** @return int сколько событий переставлено в очередь */
    public function execute(): int
    {
        $eventIds = $this->events->unappliedEventIdsOlderThan(
            Cfg::drainAfterSeconds(),
            Cfg::drainBatchSize(),
        );

        foreach ($eventIds as $eventId) {
            ApplyPaymentEventJob::dispatch($eventId);
            StructuredLog::webhook('payment_redispatched', $eventId);
        }

        return count($eventIds);
    }

    /**
     * Осиротевшие события конкретного заказа — вызывается сразу после создания
     * заказа, чтобы не ждать минуту до фонового прохода.
     */
    public function forOrder(string $orderPublicId): int
    {
        $eventIds = $this->events->unappliedEventIdsForOrder($orderPublicId);

        foreach ($eventIds as $eventId) {
            ApplyPaymentEventJob::dispatch($eventId);
            StructuredLog::webhook('orphan_event_adopted', $eventId, $orderPublicId);
        }

        return count($eventIds);
    }
}
