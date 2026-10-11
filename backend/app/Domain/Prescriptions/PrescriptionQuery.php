<?php

namespace App\Domain\Prescriptions;

use App\Enums\DispensationStatus;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prescripciones de un paciente con lo que falta por entregar de cada línea,
 * para la pantalla de dispensación.
 *
 * Por línea: prescrito, entregado (quantity_dispensed) y reservado por
 * dispensaciones PENDIENTE_AUTORIZACION (S-31). Disponible para pedir =
 * prescrito - entregado - reservado (la misma cuenta que valida
 * DispenseService; ahí se vuelve a calcular con las filas bloqueadas).
 */
final class PrescriptionQuery
{
    /** Las más recientes primero; un tope evita respuestas enormes. */
    public const LIMIT = 50;

    /**
     * @return Collection<int, Prescription>
     */
    public function forPatient(Patient $patient): Collection
    {
        return Prescription::query()
            ->where('patient_id', $patient->id)
            ->with(self::relations())
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    public function load(Prescription $prescription): Prescription
    {
        return $prescription->load(self::relations());
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function relations(): array
    {
        return [
            'prescriber',
            'items' => fn (HasMany $items) => $items
                ->orderBy('id')
                ->with('product')
                ->withSum([
                    'dispensationItems as quantity_reserved' => fn (Builder $q) => $q->whereHas(
                        'dispensation',
                        fn (Builder $d) => $d->where('status', DispensationStatus::PendienteAutorizacion->value),
                    ),
                ], 'quantity'),
        ];
    }
}
