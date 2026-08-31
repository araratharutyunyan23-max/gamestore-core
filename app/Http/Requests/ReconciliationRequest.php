<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Сверка показывает внутреннее состояние денег, поэтому эндпоинт закрыт
 * токеном из конфигурации. Полноценной авторизации в задании нет и не
 * требуется, но оставлять такое открытым нельзя.
 */
final class ReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = config()->string('ops.token');
        $provided = $this->header('X-Ops-Token');

        if ($expected === '' || ! is_string($provided)) {
            return false;
        }

        // hash_equals, а не ===: сравнение с ранним выходом утекает информацию
        // о токене по времени ответа.
        return hash_equals($expected, $provided);
    }

    protected function failedAuthorization(): never
    {
        throw new AccessDeniedHttpException('Неверный или отсутствующий заголовок X-Ops-Token.');
    }

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
