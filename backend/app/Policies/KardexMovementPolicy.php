<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Kardex: consulta para personal de farmacia y auditor; ajustes manuales
 * (movimiento AJUSTE) solo regente (RN-06, CLAUDE.md §5).
 */
class KardexMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }

    public function adjust(User $user): bool
    {
        return $user->isActiveWithRole(Role::RegenteFarmacia);
    }
}
