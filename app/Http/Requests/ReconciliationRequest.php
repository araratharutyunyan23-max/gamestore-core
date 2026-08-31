<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Сверка показывает внутреннее состояние денег, поэтому эндпоинт закрыт
 * токеном из конфигурации. Полноценной авторизации в задании нет и не
 * требуется, но оставлять такое открытым нельзя.
 */
final class ReconciliationRequest extends OpsRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['full' => ['sometimes', 'boolean']];
    }

    public function full(): bool
    {
        return $this->boolean('full');
    }
}
