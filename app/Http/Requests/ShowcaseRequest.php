<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Catalog\DTO\ShowcaseCursor;
use App\Domain\Catalog\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShowcaseRequest extends FormRequest
{
    private const MAX_LIMIT = 50;

    private const DEFAULT_LIMIT = 25;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(ProductType::values())],
            // Потолок обязателен: без него один запрос с limit=1000000
            // вытягивает всю витрину и кладёт базу.
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function type(): ?ProductType
    {
        $value = $this->query('type');

        return is_string($value) ? ProductType::tryFrom($value) : null;
    }

    public function cursor(): ?ShowcaseCursor
    {
        $value = $this->query('cursor');

        return ShowcaseCursor::decode(is_string($value) ? $value : null);
    }

    public function limit(): int
    {
        $value = $this->query('limit');

        return is_numeric($value) ? min((int) $value, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
    }
}
