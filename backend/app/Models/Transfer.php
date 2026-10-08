<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traslado entre bodegas (RN-07, RN-08).
 *
 * @property int $id
 * @property int $origin_warehouse_id
 * @property int $destination_warehouse_id
 * @property TransferStatus $status
 * @property string|null $notes Texto libre: NO confiable (posible inyección hacia el asistente IA).
 * @property int $requested_by
 * @property CarbonImmutable|null $requested_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $dispatched_by
 * @property CarbonImmutable|null $dispatched_at
 * @property int|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 */
class Transfer extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'origin_warehouse_id',
        'destination_warehouse_id',
        'status',
        'notes',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'dispatched_by',
        'dispatched_at',
        'received_by',
        'received_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function originWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<TransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }
}
