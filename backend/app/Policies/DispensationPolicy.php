<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Dispensation;
use App\Models\User;

/**
 * Permisos de dispensación (CLAUDE.md §5). La segregación de funciones
 * (regente distinto del creador, RN-05) es regla de negocio y la valida
 * DispenseService con un error específico (SEGREGACION_FUNCIONES).
 */
class DispensationPolicy
{
    /** Crear dispensaciones y ver la vista previa FEFO. */
    public function create(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia);
    }

    public function view(User $user, Dispensation $dispensation): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }

    /** Autorizar o rechazar un medicamento de control especial. */
    public function decide(User $user, Dispensation $dispensation): bool
    {
        return $user->isActiveWithRole(Role::RegenteFarmacia);
    }
}
