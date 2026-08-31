<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\CallOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryAttemptRepository;
use App\Domain\Delivery\Repositories\SupplierCodeRepository;
use App\Domain\Delivery\Suppliers\SupplierRegistry;
use App\Domain\Ordering\Repositories\OrderRepository;
use App\Models\Order;
use App\Support\Cfg;
use App\Support\StructuredLog;
use Illuminate\Support\Sleep;

/**
 * Выяснение судьбы обращения, закончившегося неизвестностью.
 *
 * Это ответ на ловушку задания. Таймаут не говорит ничего: код мог быть выдан
 * и списан, а ответ не дойти. Спрашивать нужно у самого поставщика, и спрашивать
 * правильно.
 *
 * Порядок такой:
 *
 * 1. Выждать. Probe, отправленный сразу, обгонит ещё живую обработку и ответит
 *    «не знаю такого» за миллисекунды до выдачи кода. Ответ окажется ложью,
 *    и именно на нём система ушла бы ко второму поставщику.
 * 2. Probe. Четыре ответа, а не два: issued (код есть — забираем),
 *    in_flight (обработка идёт — ждём дальше), sealed (обязательство не
 *    выдавать — можно ко второму), not_found (пока не выдал — НЕ доказательство).
 * 3. Печать. not_found сам по себе замок не снимает. Снимает только успешная
 *    печать: поставщик обязуется никогда не выдать код по этому request_id.
 *    Это превращает «пока не выдал» в «не выдаст» — единственный безопасный
 *    способ уйти ко второму.
 */
final readonly class ResolveSupplierAttempt
{
    public function __construct(
        private SupplierRegistry $suppliers,
        private DeliveryAttemptRepository $attempts,
        private SupplierCodeRepository $codes,
        private OrderRepository $orders,
    ) {}

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    public function resolve(Order $order, SupplierName $supplier, RequestId $requestId, int $attemptId): array
    {
        $gateway = $this->suppliers->get($supplier);

        // Шаг 1. Дать поставщику доработать уже принятый запрос.
        Sleep::usleep((int) (Cfg::supplierMaxProcessing() * 1_000_000));

        $probe = $gateway->probe($requestId->value);

        StructuredLog::delivery('supplier_probe', $order->public_id, reason: $probe->outcome->value);

        if ($probe->hasCode()) {
            // Код всё-таки был выдан. Забираем его — второй покупать не нужно.
            $this->codes->capture($requestId->value, $supplier, (string) $probe->code);
            $this->attempts->finish($attemptId, AttemptOutcome::Succeeded, $probe->httpStatus, null, $probe->latencyMs, $probe->storeEpoch);

            StructuredLog::delivery('supplier_unknown_resolved', $order->public_id, substr((string) $probe->code, -4));

            return [
                'outcome' => DeliveryOutcome::Delivered,
                'code' => $probe->code,
                'request_id' => $requestId->value,
                'supplier' => $supplier,
            ];
        }

        if ($probe->outcome === CallOutcome::InFlight) {
            // Обработка идёт прямо сейчас. Уходить ко второму нельзя ни при
            // каких условиях: код вот-вот появится.
            $this->attempts->finish($attemptId, AttemptOutcome::Unknown, $probe->httpStatus, 'in_flight', $probe->latencyMs, $probe->storeEpoch);
            $this->attempts->scheduleProbe($attemptId, self::PROBE_BACKOFF_SECONDS);

            return $this->pending();
        }

        // Шаг 3. not_found или неизвестность — пробуем запечатать.
        $seal = $gateway->seal($requestId->value);

        StructuredLog::delivery('supplier_seal', $order->public_id, reason: $seal->outcome->value);

        if ($seal->hasCode()) {
            // Печать наткнулась на уже выданный код — забираем его.
            $this->codes->capture($requestId->value, $supplier, (string) $seal->code);
            $this->attempts->finish($attemptId, AttemptOutcome::Succeeded, $seal->httpStatus, null, $seal->latencyMs, $seal->storeEpoch);

            return [
                'outcome' => DeliveryOutcome::Delivered,
                'code' => $seal->code,
                'request_id' => $requestId->value,
                'supplier' => $supplier,
            ];
        }

        if ($seal->outcome === CallOutcome::Sealed) {
            // Доказано: код по этому request_id не появится никогда.
            $this->attempts->finish($attemptId, AttemptOutcome::Sealed, $seal->httpStatus, 'sealed', $seal->latencyMs, $seal->storeEpoch);

            // Печать — доказательство отсутствия выдачи, значит эпоху двигать
            // можно и нужно: следующий request_id обязан быть другим.
            $this->orders->bumpDeliveryEpoch($order->id);

            return $this->exhausted();
        }

        // Запечатать не удалось (обработка ещё идёт или поставщик недоступен).
        // Судьба остаётся неизвестной, и это НЕ повод идти ко второму.
        $this->attempts->finish($attemptId, AttemptOutcome::Unknown, $seal->httpStatus, $seal->errorKind, $seal->latencyMs, $seal->storeEpoch);
        $this->attempts->scheduleProbe($attemptId, self::PROBE_BACKOFF_SECONDS);

        return $this->pending();
    }

    private const PROBE_BACKOFF_SECONDS = 30;

    /**
     * @return array{outcome: DeliveryOutcome, code: null, request_id: null, supplier: null}
     */
    private function pending(): array
    {
        return ['outcome' => DeliveryOutcome::AwaitingResolution, 'code' => null, 'request_id' => null, 'supplier' => null];
    }

    /**
     * @return array{outcome: DeliveryOutcome, code: null, request_id: null, supplier: null}
     */
    private function exhausted(): array
    {
        return ['outcome' => DeliveryOutcome::SupplierExhausted, 'code' => null, 'request_id' => null, 'supplier' => null];
    }
}
