<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * История денег: остатки на момент либо обороты за период.
 *
 * Под тем же эксплуатационным токеном, что сверка и метрики: это внутреннее
 * состояние денег, и публичным оно быть не может.
 */
final class LedgerHistoryRequest extends OpsRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date', 'before_or_equal:now'],
            'from' => ['sometimes', 'date', 'required_with:to'],
            'to' => ['sometimes', 'date', 'required_with:from', 'after:from'],
        ];
    }

    public function asOf(): CarbonImmutable
    {
        $raw = $this->query('as_of');

        // Без параметра — «сейчас». Тот же эндпоинт отвечает и на вопрос
        // о текущем состоянии, и о прошлом: это один вопрос с параметром.
        return is_string($raw) && trim($raw) !== ''
            ? CarbonImmutable::parse($raw)
            : CarbonImmutable::now();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function period(): ?array
    {
        $from = $this->query('from');
        $to = $this->query('to');

        if (! is_string($from) || ! is_string($to)) {
            return null;
        }

        return [CarbonImmutable::parse($from), CarbonImmutable::parse($to)];
    }
}
