<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Общая проверка эксплуатационного токена.
 *
 * Вынесена в базовый класс, потому что второй эндпоинт под тем же токеном
 * означал бы вторую копию сравнения — а копия проверки доступа рано или
 * поздно расходится с оригиналом, и расходится в сторону слабее.
 */
abstract class OpsRequest extends FormRequest
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
}
