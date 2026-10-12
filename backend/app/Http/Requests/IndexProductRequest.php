<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/products?q=&per_page=
 */
class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string|null, per_page: int}
     */
    public function filters(): array
    {
        $q = $this->string('q')->trim()->toString();

        return [
            'q' => $q === '' ? null : $q,
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
