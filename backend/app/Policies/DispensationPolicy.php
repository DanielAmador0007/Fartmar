<?php

namespace App\Policies;

use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Enums\Role;
use App\Models\Dispensation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos de dispensación (CLAUDE.md §5). Siempre usuario activo (S-37).
 *
 * - Crear y vista previa FEFO: auxiliar y regente.
 * - Ver (listado y detalle): auxiliar, regente y auditor.
 * - Autorizar / rechazar un controlado (RN-05): regente distinto de quien
 *   creó la dispensación; si es el creador se niega con
 *   SEGREGACION_FUNCIONES (BusinessDenial). DispenseService y la BD lo
 *   vuelven a exigir con la fila bloqueada.
 *
 * El estado (solo PENDIENTE_AUTORIZACION) no es permiso: lo valida el
 * servicio (TRANSICION_INVALIDA) y DispensationActions para allowed_actions.
 */
class DispensationPolicy
{
    /** Crear dispensaciones y ver la vista previa FEFO. */
    public function create(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia);
    }

    /** Listado: mismos lectores que el detalle (farmacia y auditor). */
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }

    public function view(User $user, Dispensation $dispensation): bool
    {
        return $this->viewAny($user);
    }

    /** RN-05: autorizar un medicamento de control especial. */
    public function authorize(User $user, Dispensation $dispensation): Response
    {
        return $this->secondRegente($user, $dispensation, 'autorizar la dispensación');
    }

    /** RN-05: rechazar un medicamento de control especial. */
    public function reject(User $user, Dispensation $dispensation): Response
    {
        return $this->secondRegente($user, $dispensation, 'rechazar la dispensación');
    }

    /**
     * Regente activo y distinto del creador. Sin rol: negación genérica
     * (NO_AUTORIZADO). Con rol pero es el creador: SEGREGACION_FUNCIONES.
     * Solo mira columnas ya cargadas (sin consultas: apto para listados).
     */
    private function secondRegente(User $user, Dispensation $dispensation, string $action): Response
    {
        if (! $user->isActiveWithRole(Role::RegenteFarmacia)) {
            return Response::deny();
        }

        if ($user->id === $dispensation->created_by) {
            return BusinessDenial::because(SegregationOfDutiesException::sameUser($action));
        }

        return Response::allow();
    }
}
