<?php

namespace App\Domain\Dispensing;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Qué acciones puede ejecutar AHORA un usuario sobre una dispensación
 * (equivalente a TransferActions): la dispensación debe estar en
 * PENDIENTE_AUTORIZACION Y DispensationPolicy debe permitir la acción
 * (regente activo, distinto del creador).
 *
 * Solo informa al frontend qué botones mostrar; cada endpoint vuelve a
 * autorizar y DispenseService vuelve a validar con la fila bloqueada. Sin
 * consultas a la BD (sin N+1 en el listado).
 */
final class DispensationActions
{
    /** Acción = nombre del método de la Policy y del endpoint. */
    private const ACTIONS = ['authorize', 'reject'];

    /**
     * @return list<string>
     */
    public function allowedFor(User $user, Dispensation $dispensation): array
    {
        if ($dispensation->status !== DispensationStatus::PendienteAutorizacion) {
            return [];
        }

        $gate = Gate::forUser($user);

        return array_values(array_filter(
            self::ACTIONS,
            fn (string $action): bool => $gate->allows($action, $dispensation),
        ));
    }
}
