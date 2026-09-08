<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTO\DeliveryOutcome;
use App\Domain\Delivery\DTO\DeliveryTarget;
use App\Domain\Delivery\DTO\IssueRequest;
use App\Domain\Delivery\DTO\RequestId;
use App\Domain\Delivery\DTO\SupplierResponse;
use App\Domain\Delivery\Enums\AttemptOutcome;
use App\Domain\Delivery\Enums\CallOutcome;
use App\Domain\Delivery\Enums\SupplierName;
use App\Domain\Delivery\Repositories\DeliveryAttemptRepository;
use App\Domain\Delivery\Repositories\SupplierCodeRepository;
use App\Domain\Delivery\Suppliers\SupplierRegistry;
use App\Domain\Ordering\Repositories\OrderItemRepository;
use App\Domain\Reconciliation\Enums\FindingKind;
use App\Domain\Reconciliation\Repositories\ReconciliationFindingRepository;
use App\Models\LicenseKey;
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
        private OrderItemRepository $items,
        private ReconciliationFindingRepository $findings,
    ) {}

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    public function execute(DeliveryTarget $target): array
    {
        $supplier = SupplierName::primary();
        $epoch = $target->deliveryEpoch;

        while (true) {
            $result = $this->trySupplier($target, $supplier, $epoch);

            if ($result['outcome'] !== DeliveryOutcome::SupplierExhausted) {
                return $result;
            }

            $next = $supplier->fallback();

            if ($next === null) {
                return $this->failure(DeliveryOutcome::DeliveryFailed);
            }

            StructuredLog::supplier(
                'supplier_failover',
                $target->orderPublicId,
                $supplier,
                RequestId::for($target->orderPublicId, $target->lineNo, $supplier, $epoch),
                outcome: 'exhausted',
                reason: 'fallback_to:'.$next->value,
            );

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
    private function trySupplier(DeliveryTarget $target, SupplierName $supplier, int $epoch): array
    {
        $requestId = RequestId::for($target->orderPublicId, $target->lineNo, $supplier, $epoch);
        $gateway = $this->suppliers->get($supplier);

        // Шаг 1: намерение фиксируется до вызова и переживает падение процесса.
        $attemptId = $this->attempts->begin($target, $supplier, $requestId, $epoch, StructuredLog::traceId());

        StructuredLog::supplier('supplier_call', $target->orderPublicId, $supplier, $requestId);

        // Шаг 2: вызов вне транзакции.
        $response = $gateway->issue(new IssueRequest($requestId->value, $target->sku, $target->orderPublicId));

        if ($response->hasCode()) {
            return $this->captureCode($target, $supplier, $requestId, $attemptId, $response);
        }

        if ($response->outcome === CallOutcome::NotIssuedCertain) {
            // Доказанный отказ — только он открывает путь ко второму поставщику.
            $this->attempts->finish($attemptId, AttemptOutcome::Failed, $response->httpStatus, $response->errorKind, $response->latencyMs, $response->storeEpoch);

            // Эпоха ПЕРСИСТИТСЯ, а не живёт в памяти. Иначе повторная выдача
            // после восстановимого отказа пойдёт с тем же request_id и упрётся
            // в delivery_attempts_request_uq — заказ уже не доведёшь никогда.
            $this->items->bumpDeliveryEpoch($target->itemId);

            StructuredLog::supplier(
                'supplier_refused',
                $target->orderPublicId,
                $supplier,
                $requestId,
                outcome: 'not_issued_certain',
                latencyMs: $response->latencyMs,
                reason: $response->errorKind ?? 'unknown',
            );

            return $this->failure(DeliveryOutcome::SupplierExhausted);
        }

        // Неизвестность. Судьбу выясняет отдельный сервис: сам факт таймаута
        // не говорит ничего о том, выдан код или нет.
        // Таймаут выделен отдельным событием не для красоты: это единственный
        // исход, при котором мы НЕ ЗНАЕМ, выдан код или нет, и по его доле
        // судят, можно ли вообще доверять поставщику.
        if ($response->errorKind === null) {
            StructuredLog::supplier(
                'supplier_timeout',
                $target->orderPublicId,
                $supplier,
                $requestId,
                outcome: 'unknown',
                latencyMs: $response->latencyMs,
                reason: 'timeout',
            );
        } else {
            StructuredLog::supplier(
                'supplier_unknown',
                $target->orderPublicId,
                $supplier,
                $requestId,
                outcome: 'unknown',
                latencyMs: $response->latencyMs,
                reason: $response->errorKind,
            );
        }

        return $this->resolver->resolve($target, $supplier, $requestId, $attemptId);
    }

    /**
     * @return array{outcome: DeliveryOutcome, code: ?string, request_id: ?string, supplier: ?SupplierName}
     */
    private function captureCode(
        DeliveryTarget $target,
        SupplierName $supplier,
        RequestId $requestId,
        int $attemptId,
        SupplierResponse $response,
    ): array {
        $code = (string) $response->code;

        // Шаг 3а: ответу поставщика НЕ ДОВЕРЯЕМ. Проверки идут до того, как
        // код попадёт покупателю — потом будет поздно: отданный код не
        // отзывается.
        $rejection = $this->reasonToReject($target, $requestId, $code, $response);

        if ($rejection !== null) {
            return $this->rejectCode($target, $supplier, $requestId, $attemptId, $response, $rejection);
        }

        // Шаг 3б: код записан отдельно и раньше всего остального. С этого
        // момента его нельзя потерять откатом бизнес-транзакции.
        if (! $this->codes->capture($requestId->value, $supplier, $code)) {
            // Вставка не прошла. Два совершенно разных случая под одним
            // возвратом, и путать их нельзя.
            $ours = $this->codes->findByRequestId($requestId->value);

            if ($ours === null) {
                // Код уже принадлежит ДРУГОМУ запросу: поставщик отдал чужой.
                return $this->rejectCode(
                    $target, $supplier, $requestId, $attemptId, $response,
                    FindingKind::SupplierDuplicateCode,
                );
            }

            if ($ours->codeHash !== LicenseKey::fingerprint($code)) {
                // По нашему же запросу поставщик прислал ДРУГОЙ код, чем в
                // прошлый раз. Повтор обязан быть идемпотентным; раз он не
                // идемпотентен, доверять этому ответу нельзя.
                return $this->rejectCode(
                    $target, $supplier, $requestId, $attemptId, $response,
                    FindingKind::SupplierDuplicateCode,
                );
            }
        }

        $this->attempts->finish($attemptId, AttemptOutcome::Succeeded, $response->httpStatus, null, $response->latencyMs, $response->storeEpoch);

        StructuredLog::supplier(
            'supplier_issued',
            $target->orderPublicId,
            $supplier,
            $requestId,
            outcome: 'issued',
            latencyMs: $response->latencyMs,
            codeLast4: substr((string) $response->code, -4),
        );

        return [
            'outcome' => DeliveryOutcome::Delivered,
            'code' => $response->code,
            'request_id' => $requestId->value,
            'supplier' => $supplier,
        ];
    }

    /**
     * Причина не принимать код, или null — код выглядит честным.
     *
     * Проверяется то, что мы способны проверить САМИ. Что открывает чужая
     * строка символов, снаружи не узнать, поэтому «код от другого товара»
     * ловится по объявленному поставщиком SKU — и это честная граница
     * возможностей, а не полная защита. Дубль, наоборот, ловится независимо:
     * мы помним каждый код, который когда-либо получали.
     */
    private function reasonToReject(
        DeliveryTarget $target,
        RequestId $requestId,
        string $code,
        SupplierResponse $response,
    ): ?FindingKind {
        if ($response->sku !== null && $response->sku !== $target->sku) {
            return FindingKind::SupplierForeignCode;
        }

        $owner = $this->codes->ownerOfCode(LicenseKey::fingerprint($code));

        // Владелец, совпадающий с нашим запросом, — это честный повтор:
        // поставщик обязан отвечать тем же кодом на тот же request_id, и
        // именно на этом держится безопасность повтора после таймаута.
        if ($owner !== null && $owner !== $requestId->value) {
            return FindingKind::SupplierDuplicateCode;
        }

        return null;
    }

    /**
     * Код не принят: позиция не выдаётся, расхождение заводится в сверку.
     *
     * Исход попытки — Failed, то есть ДОКАЗАННОЕ «нам код не выдан». Это
     * открывает путь ко второму поставщику, и так и должно быть: код,
     * который принадлежит чужому заказу, для этой позиции не существует.
     *
     * Но поставщик его у себя списал, и за него, возможно, заплачено. Поэтому
     * код записывается как отвергнутый, а расхождение — в сверку: разбор идёт
     * автоматически (ТЗ 2.4), а не «когда-нибудь руками».
     *
     * @return array{outcome: DeliveryOutcome, code: null, request_id: null, supplier: null}
     */
    private function rejectCode(
        DeliveryTarget $target,
        SupplierName $supplier,
        RequestId $requestId,
        int $attemptId,
        SupplierResponse $response,
        FindingKind $kind,
    ): array {
        $this->codes->reject($requestId->value, $supplier, (string) $response->code);

        $this->attempts->finish(
            $attemptId,
            AttemptOutcome::Failed,
            $response->httpStatus,
            $kind->value,
            $response->latencyMs,
            $response->storeEpoch,
        );

        $this->items->bumpDeliveryEpoch($target->itemId);

        $this->findings->record($kind, $target->orderId, $target->reference(), [
            'supplier' => $supplier->value,
            'request_id' => $requestId->value,
            'declared_sku' => $response->sku,
            'expected_sku' => $target->sku,
            'code_last4' => substr((string) $response->code, -4),
        ]);

        StructuredLog::supplier(
            'supplier_code_rejected',
            $target->orderPublicId,
            $supplier,
            $requestId,
            outcome: 'rejected',
            latencyMs: $response->latencyMs,
            reason: $kind->value,
        );

        return $this->failure(DeliveryOutcome::SupplierExhausted);
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
