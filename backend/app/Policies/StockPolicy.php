<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Consulta de inventario: personal de farmacia y auditor (solo lectura).
 */
class StockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }
}
