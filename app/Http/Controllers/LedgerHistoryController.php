<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\History\Repositories\HistoryRepository;
use App\Http\Requests\LedgerHistoryRequest;
use Illuminate\Http\JsonResponse;

/**
 * Деньги на момент или за период (ТЗ 4.1 и 4.3).
 *
 * Оба ответа считаются из одного и того же append-only журнала проводок,
 * и это не экономия кода: если бы обороты считались из одного источника,
 * а остатки из другого, «итоги сходятся с остатком» превратилось бы
 * в совпадение, которое однажды перестанет случаться.
 */
final class LedgerHistoryController
{
    public function __construct(private readonly HistoryRepository $history) {}

    public function __invoke(LedgerHistoryRequest $request): JsonResponse
    {
        $period = $request->period();

        if ($period !== null) {
            [$from, $to] = $period;

            return response()->json([
                'data' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                    // Границы полуоткрытые: (from, to]. Иначе два соседних
                    // периода пересекаются, и сумма оборотов не сходится
                    // с остатком на конец.
                    'opening' => $this->history->accountBalancesAsOf($from),
                    'turnover' => $this->history->accountTurnoverBetween($from, $to),
                    'closing' => $this->history->accountBalancesAsOf($to),
                ],
            ]);
        }

        $asOf = $request->asOf();

        return response()->json([
            'data' => [
                'as_of' => $asOf->toIso8601String(),
                'balances' => $this->history->accountBalancesAsOf($asOf),
            ],
        ]);
    }
}
