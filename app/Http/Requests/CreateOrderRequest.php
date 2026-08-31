<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ordering\DTO\CreateOrderCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Вся валидация создания заказа. В контроллере проверок нет ни одной.
 */
final class CreateOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Ключ идемпотентности ОБЯЗАТЕЛЕН и берётся только из заголовка.
            // Генерировать его самим нельзя: сгенерированный на сервере ключ
            // уникален на каждый запрос, то есть повтор создаст второй заказ —
            // ровно то, от чего заголовок и защищает.
            if ($this->idempotencyKey() === null) {
                $validator->errors()->add('Idempotency-Key', 'Заголовок Idempotency-Key обязателен.');
            }
        });
    }

    public function toCommand(): CreateOrderCommand
    {
        return new CreateOrderCommand(
            sku: $this->string('sku')->toString(),
            idempotencyKey: (string) $this->idempotencyKey(),
        );
    }

    private function idempotencyKey(): ?string
    {
        $header = $this->header('Idempotency-Key');

        return is_string($header) && trim($header) !== '' ? trim($header) : null;
    }
}
