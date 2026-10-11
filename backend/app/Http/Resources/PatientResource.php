<?php

namespace App\Http\Resources;

use App\Domain\Patients\PatientMasker;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paciente según el rol (RN-10). Mismas claves para todos los roles, para
 * que el frontend no cambie de forma; "masked" indica si se enmascaró.
 *
 * Si no hay usuario o no tiene permiso de ver sin máscara (auditor), se
 * enmascara: el valor seguro es el predeterminado.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $masked = ! ($request->user()?->can('viewUnmasked', $this->resource) ?? false);

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_number' => $masked ? PatientMasker::document($this->document_number) : $this->document_number,
            'first_name' => $masked ? PatientMasker::name($this->first_name) : $this->first_name,
            'last_name' => $masked ? PatientMasker::name($this->last_name) : $this->last_name,
            'birth_date' => $masked ? null : $this->birth_date->toDateString(),
            'phone' => $masked ? null : $this->phone,
            'masked' => $masked,
        ];
    }
}
