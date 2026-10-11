<?php

namespace App\Policies;

use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Enums\Role;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos de traslados (CLAUDE.md §5, S-49). Siempre usuario activo (S-37).
 *
 * - Ver: auxiliar, regente y auditor (solo lectura).
 * - Crear, despachar y recibir: auxiliar y regente.
 * - Solicitar (enviar el borrador): solo quien lo creó.
 * - Aprobar: regente distinto del solicitante (RN-08); si es el solicitante
 *   se niega con SEGREGACION_FUNCIONES (BusinessDenial).
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

    /**
     * RN-08: regente activo y distinto de quien solicitó. Si es quien
     * solicitó, niega con SEGREGACION_FUNCIONES (no con NO_AUTORIZADO): el
     * rol sí lo tiene, lo que falla es la segregación. TransferService y la
     * BD lo vuelven a exigir.
     */
    public function approve(User $user, Transfer $transfer): Response
    {
        if (! $user->isActiveWithRole(Role::RegenteFarmacia)) {
            return Response::deny();
        }

        if ($user->id === $transfer->requested_by) {
            return BusinessDenial::because(SegregationOfDutiesException::sameUser('aprobar el traslado'));
        }

        return Response::allow();
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
