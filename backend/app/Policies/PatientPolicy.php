<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Patient;
use App\Models\User;

/**
 * Acceso a datos de pacientes (RN-10, CLAUDE.md §5, S-68).
 *
 * - Farmacia (auxiliar, regente) y médico: datos completos (los necesitan
 *   para dispensar y prescribir).
 * - Auditor: solo lectura y ENMASCARADO (viewUnmasked = false).
 * - Admin: sin acceso. Administra usuarios y catálogos; no necesita datos
 *   clínicos (mínimo privilegio).
 */
class PatientPolicy
{
    /** Buscar pacientes por documento. */
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Medico, Role::Auditor);
    }

    /** Ver un paciente y sus prescripciones. */
    public function view(User $user, Patient $patient): bool
    {
        return $this->viewAny($user);
    }

    /** Ver documento, nombre, teléfono y fecha de nacimiento sin máscara. */
    public function viewUnmasked(User $user, ?Patient $patient = null): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Medico);
    }
}
