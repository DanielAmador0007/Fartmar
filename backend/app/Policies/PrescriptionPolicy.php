<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Prescripciones (CLAUDE.md §5): solo el médico las crea. La lectura va por
 * GET /patients/{id}/prescriptions con PatientPolicy::view (y bitácora).
 */
class PrescriptionPolicy
{
    public function create(User $user): bool
    {
        return $user->isActiveWithRole(Role::Medico);
    }
}
