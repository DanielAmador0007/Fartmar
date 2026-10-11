<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/users/{user} (solo admin): nombre, rol y activo/inactivo.
 * El correo y la contraseña no se cambian por aquí.
 */
class UpdateUserRequest extends FormRequest
{
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
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_intersect(array_keys($this->all()), ['name', 'role', 'is_active']) === []) {
                    $validator->errors()->add('name', 'Envíe al menos uno de: name, role, is_active.');
                }
            },
        ];
    }

    /**
     * @return array{name?: string, role?: Role, is_active?: bool}
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

        return $changes;
    }
}
