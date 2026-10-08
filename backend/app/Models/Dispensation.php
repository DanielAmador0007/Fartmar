<?php

namespace App\Models;

use App\Enums\DispensationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dispensación (RN-02, RN-04, RN-05, RN-09).
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescription_id
 * @property int $warehouse_id
 * @property DispensationStatus $status
 * @property bool $requires_authorization
 * @property int $created_by
 * @property int|null $authorized_by
 * @property CarbonImmutable|null $authorized_at
 * @property int|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 */
class Dispensation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'patient_id',
        'prescription_id',
        'warehouse_id',
        'status',
        'requires_authorization',
        'created_by',
        'authorized_by',
        'authorized_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'idempotency_key',
        'request_hash',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DispensationStatus::class,
            'requires_authorization' => 'boolean',
            'authorized_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return HasMany<DispensationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DispensationItem::class);
    }
}
