<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Catálogo de bodegas (solo lectura). Lo necesitan todos los roles activos:
 * farmacia (dispensar, trasladar), médico, auditor (filtros) y admin
 * (administra catálogos). No contiene datos personales.
 */
class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(...Role::cases());
    }
}
