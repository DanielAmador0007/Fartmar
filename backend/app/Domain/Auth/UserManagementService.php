<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\SelfLockoutException;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Administración de usuarios (rol admin, UserPolicy).
 *
 * - Al desactivar a alguien o cambiarle el rol se revocan TODOS sus tokens:
 *   la sesión que tenía abierta se creó con los permisos anteriores.
 *   (S-41 ya rechaza tokens de usuarios inactivos en cada petición; revocar
 *   además los borra y cubre el cambio de rol.)
 * - audit_logs guarda qué cambió, sin contraseñas ni nombres/correos.
 * - Sin borrado: un usuario con historial (kardex, dispensaciones) se
 *   desactiva, nunca se elimina.
 */
final class UserManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, password: string, role: Role}  $data
     */
    public function create(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // cast "hashed" la convierte en bcrypt
                'role' => $data['role'],
                'is_active' => true,
            ]);

            $this->audit->record($admin, 'user.created', $user, ['role' => $data['role']->value]);

            return $user;
        });
    }

    /**
     * @param  array{name?: string, role?: Role, is_active?: bool}  $changes
     */
    public function update(User $admin, User $user, array $changes): User
    {
        $losesAdmin = isset($changes['role']) && $changes['role'] !== Role::Admin;
        $deactivates = ($changes['is_active'] ?? true) === false;
        if ($admin->is($user) && ($losesAdmin || $deactivates)) {
            throw SelfLockoutException::make();
        }

        return DB::transaction(function () use ($admin, $user, $changes): User {
            $before = ['role' => $user->role->value, 'is_active' => $user->is_active];
            $user->fill($changes);
            $dirty = array_keys($user->getDirty());

            if ($dirty === []) {
                return $user;
            }

            $user->save();

            $revokeTokens = in_array('role', $dirty, true) || (in_array('is_active', $dirty, true) && ! $user->is_active);
            $revoked = $revokeTokens ? $user->tokens()->delete() : 0;

            // Valores de rol y estado (no son datos personales); del nombre solo
            // se registra que cambió.
            $metadata = ['changed' => $dirty, 'tokens_revoked' => $revoked];
            if (in_array('role', $dirty, true)) {
                $metadata['role'] = ['from' => $before['role'], 'to' => $user->role->value];
            }
            if (in_array('is_active', $dirty, true)) {
                $metadata['is_active'] = ['from' => $before['is_active'], 'to' => $user->is_active];
            }

            $this->audit->record($admin, 'user.updated', $user, $metadata);

            return $user;
        });
    }
}
