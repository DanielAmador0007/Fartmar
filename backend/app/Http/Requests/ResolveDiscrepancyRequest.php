<?php

namespace App\Http\Requests;

use App\Enums\DiscrepancyResolution;
use App\Models\TransferDiscrepancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/transfer-discrepancies/{id}/resolve
 */
class ResolveDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $discrepancy = $this->route('discrepancy');

        return $discrepancy instanceof TransferDiscrepancy
            && ($this->user()?->can('resolve', $discrepancy) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(DiscrepancyResolution::class)],
            'resolution' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Indique si las unidades vuelven al origen (REINTEGRAR_ORIGEN) o se declaran perdidas (PERDIDA).',
            'action.*' => 'La acción debe ser REINTEGRAR_ORIGEN o PERDIDA.',
            'resolution.required' => 'Explique cómo se resolvió la discrepancia.',
            'resolution.min' => 'La explicación es muy corta.',
        ];
    }

    public function resolutionAction(): DiscrepancyResolution
    {
        return DiscrepancyResolution::from($this->string('action')->toString());
    }
}
