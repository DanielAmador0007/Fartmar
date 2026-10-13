<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/assistant/query  { "question": "..." }
 *
 * Gate useAssistant (S-89): quien puede leer inventario.
 */
class AssistantQueryRequest extends FormRequest
{
    public const MAX_LENGTH = 500;

    public function authorize(): bool
    {
        return $this->user()?->can('useAssistant') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function question(): string
    {
        return $this->string('question')->trim()->toString();
    }
}
