<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\IssueRequest;
use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\DTO\SupplierResponse;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\CallOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryAttemptRepository;
use App\Domain\Delivery\Repositories\SupplierCodeRepository;
use App\Domain\Delivery\Suppliers\SupplierRegistry;
use App\Models\Order;
use App\Support\Cfg;
use App\Support\StructuredLog;

/**
 * Получение кода у внешнего поставщика.
 *
 * Порядок шагов здесь — не стиль, а требование корректности, и нарушение
 * любого из них стоит второго кода на одну оплату.
 *
 * 1. Строка попытки коммитится ДО сетевого вызова. Падение процесса ровно
 *    в момент таймаута оставляет след, по которому восстановление повторит
 *    ТОТ ЖЕ request_id.
 * 2. Сетевой вызов идёт ВНЕ транзакции. Открытая транзакция во время вызова
 *    держала бы блокировки все секунды таймаута, а при сбое пришлось бы
 *    выбирать между откатом (стирает единственное доказательство, что запрос
 *    ушёл) и удержанием транзакции.
 * 3. Полученный код записывается ОТДЕЛЬНОЙ микротранзакцией — до привязки
 *    к заказу. Иначе исключение в бизнес-логике откатывает уже купленный код.
 * 4. Уход ко второму поставщику разрешён ТОЛЬКО после доказанного отсутствия
 *    выдачи у первого: конверт с бизнес-причиной, несостоявшееся соединение
 *    или успешная печать.
 */
final readonly class DeliverViaSupplier
{
    public function __construct(
        private SupplierRegistry $suppliers,
        private DeliveryAttemptRepository $attempts,
        private SupplierCodeRepository $codes,
        private ResolveSupplierAttempt $resolver,
    ) {}

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    public function execute(Order $order): array
    {
        $supplier = SupplierName::primary();
        $epoch = $order->delivery_epoch;

        while (true) {
            $result = $this->trySupplier($order, $supplier, $epoch);

            if ($result['outcome'] !== DeliveryOutcome::SupplierExhausted) {
                return $result;
            }

            $next = $supplier->fallback();

            if ($next === null) {
                return $this->failure(DeliveryOutcome::DeliveryFailed);
            }

            StructuredLog::delivery('supplier_failover', $order->public_id, reason: $supplier->value.'->'.$next->value);

            // Новый поставщик — новая эпоха, значит новый request_id. Это
            // законно ровно потому, что отсутствие выдачи у предыдущего
            // доказано: иначе мы бы сюда не дошли.
            $supplier = $next;
            $epoch++;
        }
    }

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    private function trySupplier(Order $order, SupplierName $supplier, int $epoch): array
    {
        $requestId = RequestId::for($order->public_id, $supplier, $epoch);
        $gateway = $this->suppliers->get($supplier);

        // Шаг 1: намерение фиксируется до вызова и переживает падение процесса.
        $attemptId = $this->attempts->begin($order->id, $supplier, $requestId, $epoch, StructuredLog::traceId());

        StructuredLog::delivery('supplier_call', $order->public_id, reason: $requestId->value);

        // Шаг 2: вызов вне транзакции.
        $response = $gateway->issue(new IssueRequest($requestId->value, $order->sku, $order->public_id));

        if ($response->hasCode()) {
            return $this->captureCode($order, $supplier, $requestId, $attemptId, $response);
        }

        if ($response->outcome === CallOutcome::NotIssuedCertain) {
            // Доказанный отказ — только он открывает путь ко второму поставщику.
            $this->attempts->finish($attemptId, AttemptOutcome::Failed, $response->httpStatus, $response->errorKind, $response->latencyMs, $response->storeEpoch);
            StructuredLog::delivery('supplier_refused', $order->public_id, reason: $response->errorKind ?? 'unknown');

            return $this->failure(DeliveryOutcome::SupplierExhausted);
        }

        // Неизвестность. Судьбу выясняет отдельный сервис: сам факт таймаута
        // не говорит ничего о том, выдан код или нет.
        StructuredLog::delivery('supplier_unknown', $order->public_id, reason: $response->errorKind ?? 'timeout');

        return $this->resolver->resolve($order, $supplier, $requestId, $attemptId);
    }

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    private function captureCode(
        Order $order,
        SupplierName $supplier,
        RequestId $requestId,
        int $attemptId,
        SupplierResponse $response,
    ): array {
        // Шаг 3: код записан отдельно и раньше всего остального. С этого
        // момента его нельзя потерять откатом бизнес-транзакции.
        $this->codes->capture($requestId->value, $supplier, (string) $response->code);

        $this->attempts->finish($attemptId, AttemptOutcome::Succeeded, $response->httpStatus, null, $response->latencyMs, $response->storeEpoch);

        StructuredLog::delivery('supplier_issued', $order->public_id, substr((string) $response->code, -4));

        return [
            'outcome' => DeliveryOutcome::Delivered,
            'code' => $response->code,
            'request_id' => $requestId->value,
            'supplier' => $supplier,
        ];
    }

    /**
     * @return array{outcome: DeliveryOutcome, code: null, request_id: null, supplier: null}
     */
    private function failure(DeliveryOutcome $outcome): array
    {
        return ['outcome' => $outcome, 'code' => null, 'request_id' => null, 'supplier' => null];
    }

    public function compensatedFallbackAllowed(): bool
    {
        return Cfg::allowCompensatedFallback();
    }
}
