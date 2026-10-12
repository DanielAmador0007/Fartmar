<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/users/{user} (solo admin): nombre, rol, activo/inactivo y
 * restablecer la contraseña ("password"). Una contraseña restablecida es
 * temporal: el usuario debe cambiarla al entrar (S-77). El correo no se
 * cambia por aquí.
 */
class UpdateUserRequest extends FormRequest
{
    private const FIELDS = ['name', 'role', 'is_active', 'password'];

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'role' => ['sometimes', 'string', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', ...PasswordRules::newPassword()],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_intersect(array_keys($this->all()), self::FIELDS) === []) {
                    $validator->errors()->add('name', 'Envíe al menos uno de: name, role, is_active, password.');
                }
            },
        ];
    }

    /**
     * @return array{name?: string, role?: Role, is_active?: bool, password?: string}
     */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->trim()->toString();
        }
        if ($this->has('role')) {
            $changes['role'] = Role::from($this->string('role')->toString());
        }
        if ($this->has('is_active')) {
            $changes['is_active'] = $this->boolean('is_active');
        }
        if ($this->has('password')) {
            $changes['password'] = $this->string('password')->toString();
        }

        return $changes;
    }
}
