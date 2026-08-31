<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Payments\DTO\PaymentWebhookData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Вебхук намеренно почти не валидируется.
 *
 * Ответ 4xx на осмысленный JSON означал бы, что платёжная система считает
 * доставку неудачной и повторяет её вечно. Поэтому единственное жёсткое
 * требование — тело должно быть объектом; всё остальное разбирается дальше,
 * а непонятное сохраняется как malformed и получает 200.
 *
 * Подпись не проверяем: по условию задания это упрощено.
 */
final class PaymentWebhookRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toData(): PaymentWebhookData
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->json()->all();

        return PaymentWebhookData::fromPayload($payload);
    }
}
