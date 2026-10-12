<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/password: cualquier usuario autenticado cambia SU
 * contraseña. Exige la actual (un token robado no basta para fijar otra),
 * la nueva confirmada (password_confirmation) y distinta de la actual.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Compara con el hash del usuario del guard actual (sanctum).
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'password' => [...PasswordRules::newPassword(), 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ];
    }
}
