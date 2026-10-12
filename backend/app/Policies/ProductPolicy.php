<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Catálogo de productos (solo lectura). El médico lo usa para prescribir,
 * farmacia para dispensar y trasladar, el auditor para consultar y el admin
 * administra catálogos. No contiene datos personales.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(...Role::cases());
    }
}
