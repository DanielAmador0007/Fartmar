<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/users (solo admin). El correo se normaliza a minúsculas
 * (igual que en el login) antes de validar que sea único.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // S-72/S-77: contraseña temporal; el usuario debe cambiarla al entrar.
            'password' => PasswordRules::newPassword(),
            'role' => ['required', 'string', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array{name: string, email: string, password: string, role: Role}
     */
    public function userData(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'role' => Role::from($this->string('role')->toString()),
        ];
    }
}
