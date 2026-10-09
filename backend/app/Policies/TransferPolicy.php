<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Transfer;
use App\Models\User;

/**
 * Permisos de traslados (CLAUDE.md §5, S-49). Siempre usuario activo (S-37).
 *
 * - Ver: auxiliar, regente y auditor (solo lectura).
 * - Crear, despachar y recibir: auxiliar y regente.
 * - Solicitar (enviar el borrador): solo quien lo creó.
 * - Aprobar: regente. Que sea distinto del solicitante (RN-08) lo valida
 *   TransferService con el error específico SEGREGACION_FUNCIONES.
 * - Anular: quien lo creó o un regente.
 */
class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isPharmacyStaff($user);
    }

    public function submit(User $user, Transfer $transfer): bool
    {
        return $this->isPharmacyStaff($user) && $user->id === $transfer->requested_by;
    }

    public function approve(User $user, Transfer $transfer): bool
    {
        return $user->isActiveWithRole(Role::RegenteFarmacia);
    }

    public function dispatch(User $user, Transfer $transfer): bool
    {
        return $this->isPharmacyStaff($user);
    }

    public function receive(User $user, Transfer $transfer): bool
    {
        return $this->isPharmacyStaff($user);
    }

    public function cancel(User $user, Transfer $transfer): bool
    {
        return $user->isActiveWithRole(Role::RegenteFarmacia)
            || ($this->isPharmacyStaff($user) && $user->id === $transfer->requested_by);
    }

    private function isPharmacyStaff(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia);
    }
}
