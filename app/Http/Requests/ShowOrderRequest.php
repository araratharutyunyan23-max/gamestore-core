<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Чтение заказа, возможно — на прошедший момент.
 *
 * Момент в будущем отбивается: «состояние заказа завтра» это не вопрос
 * о фактах, а гадание, и отвечать на него молча текущим состоянием значит
 * выдать сегодняшнюю правду за завтрашнюю.
 */
final class ShowOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    public function asOf(): ?CarbonImmutable
    {
        $raw = $this->query('as_of');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return CarbonImmutable::parse($raw);
    }
}
