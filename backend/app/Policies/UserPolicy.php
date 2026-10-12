<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Administración de usuarios (CLAUDE.md §5): solo admin activo. La regla
 * "no desactivarse a sí mismo" es de negocio y la valida
 * UserManagementService (OPERACION_NO_PERMITIDA).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->isActiveWithRole(Role::Admin);
    }

    public function update(User $user, User $target): bool
    {
        return $user->isActiveWithRole(Role::Admin);
    }
}
