<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TransferDiscrepancy;
use App\Models\User;

/**
 * Discrepancias de traslado: las ve el personal de farmacia y el auditor; las
 * resuelve solo un regente (además lo exigen TransferService y un trigger).
 */
class TransferDiscrepancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveWithRole(Role::AuxiliarFarmacia, Role::RegenteFarmacia, Role::Auditor);
    }

    public function resolve(User $user, TransferDiscrepancy $discrepancy): bool
    {
        return $user->isActiveWithRole(Role::RegenteFarmacia);
    }
}
