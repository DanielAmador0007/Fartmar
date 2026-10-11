<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de quién consultó qué paciente (RN-10). Solo inserción (trigger).
 * La escribe App\Domain\Patients\PatientAccessLogger.
 *
 * @property int $id
 * @property int $user_id
 * @property int $patient_id
 * @property string $action patient.search | patient.view | patient.prescriptions
 * @property bool $masked true si el usuario vio los datos enmascarados (auditor)
 * @property string|null $ip
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 */
class PatientAccessLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'patient_id', 'action', 'masked', 'ip', 'correlation_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'masked' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
