<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prescripción (RN-04). Vigente = status ACTIVA y valid_until >= hoy (zona de
 * negocio); esa regla se aplica en el servicio de dispensación.
 *
 * @property int $id
 * @property string $number
 * @property int $patient_id
 * @property int $prescriber_id
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $valid_until
 * @property PrescriptionStatus $status
 */
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['number', 'patient_id', 'prescriber_id', 'issued_at', 'valid_until', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'valid_until' => 'immutable_date',
            'status' => PrescriptionStatus::class,
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /** @return HasMany<Dispensation, $this> */
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class);
    }
}
