<?php

namespace App\Models;

use App\Domain\Patients\DocumentHasher;
use Carbon\CarbonImmutable;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paciente (datos sensibles, RN-10). document_number y phone se cifran con
 * APP_KEY; document_hash (HMAC) permite la búsqueda exacta y la unicidad.
 * Nunca serializar este modelo directamente en respuestas: usar el Resource,
 * que enmascara según el rol.
 *
 * @property int $id
 * @property string $document_type
 * @property string $document_number
 * @property string $document_hash
 * @property string $first_name
 * @property string $last_name
 * @property CarbonImmutable $birth_date
 * @property string|null $phone
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'document_type',
        'document_number',
        'first_name',
        'last_name',
        'birth_date',
        'phone',
    ];

    /** @var list<string> */
    protected $hidden = ['document_number', 'document_hash', 'phone'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'phone' => 'encrypted',
            'birth_date' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // El hash se recalcula siempre que cambie el documento, para que nunca
        // quede desincronizado con el número cifrado.
        static::saving(function (Patient $patient): void {
            if ($patient->isDirty(['document_type', 'document_number'])) {
                $patient->document_hash = DocumentHasher::hash($patient->document_type, $patient->document_number);
            }
        });
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /** @return HasMany<Dispensation, $this> */
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class);
    }
}
