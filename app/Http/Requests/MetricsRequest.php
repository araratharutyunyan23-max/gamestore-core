<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Метрики закрыты тем же токеном, что и сверка: по счётчикам заказов и
 * незакрытой предоплаты читается оборот, а это не публичные данные.
 */
final class MetricsRequest extends OpsRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
