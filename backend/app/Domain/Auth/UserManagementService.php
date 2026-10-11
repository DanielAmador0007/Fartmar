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
 * - Al desactivar a alguien, cambiarle el rol o restablecerle la contraseña
 *   se revocan TODOS sus tokens: la sesión que tenía abierta se creó con los
 *   permisos (o la clave) anteriores.
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
     * La contraseña asignada por el admin es temporal (must_change_password,
     * S-77): el usuario no puede operar hasta cambiarla.
     *
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
                'must_change_password' => true,
            ]);

            // El rol queda registrado: crear un médico o un regente es una
            // operación privilegiada que se puede revisar (riesgo residual S-77).
            $this->audit->record($admin, 'user.created', $user, ['role' => $data['role']->value]);

            return $user;
        });
    }

    /**
     * @param  array{name?: string, role?: Role, is_active?: bool, password?: string}  $changes
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

            // Restablecer = contraseña temporal (S-77).
            $passwordReset = isset($changes['password']);
            if ($passwordReset) {
                $user->must_change_password = true;
            }

            $dirty = array_keys($user->getDirty());
            if ($dirty === []) {
                return $user;
            }

            $user->save();

            // La sesión abierta se emitió con el rol, el estado o la clave anteriores.
            $revokeTokens = $passwordReset
                || in_array('role', $dirty, true)
                || (in_array('is_active', $dirty, true) && ! $user->is_active);
            $revoked = $revokeTokens ? $user->tokens()->delete() : 0;

            // Valores de rol y estado (no son datos personales); del nombre y de
            // la contraseña solo se registra que cambiaron.
            $metadata = ['changed' => $dirty, 'tokens_revoked' => $revoked];
            if (in_array('role', $dirty, true)) {
                $metadata['role'] = ['from' => $before['role'], 'to' => $user->role->value];
            }
            if (in_array('is_active', $dirty, true)) {
                $metadata['is_active'] = ['from' => $before['is_active'], 'to' => $user->is_active];
            }

            $this->audit->record($admin, $passwordReset ? 'user.password_reset' : 'user.updated', $user, $metadata);

            return $user;
        });
    }

    /**
     * POST /auth/password: el propio usuario cambia su contraseña (la actual
     * ya se validó en ChangePasswordRequest). Quita must_change_password y
     * revoca sus OTROS tokens (otra sesión abierta con la clave anterior).
     */
    public function changeOwnPassword(User $user, string $newPassword): User
    {
        return DB::transaction(function () use ($user, $newPassword): User {
            $forced = $user->must_change_password;
            $user->forceFill(['password' => $newPassword, 'must_change_password' => false])->save();

            // El token con el que se hizo la petición se conserva.
            $currentId = $user->currentAccessToken()->getKey();
            $revoked = $user->tokens()
                ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
                ->delete();

            $this->audit->record($user, 'user.password_changed', $user, [
                'forced' => $forced,
                'tokens_revoked' => $revoked,
            ]);

            return $user;
        });
    }
}
