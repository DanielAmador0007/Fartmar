<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Un usuario por rol + un segundo regente (para probar RN-05: autoriza un
 * regente distinto a quien crea, y RN-08: aprueba alguien distinto a quien
 * solicita). Contraseña de TODOS: "password" (solo demo local).
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        $users = [
            ['email' => 'admin@fartmar.test', 'name' => 'Admin Demo', 'role' => Role::Admin],
            ['email' => 'regente@fartmar.test', 'name' => 'Regente Demo Uno', 'role' => Role::RegenteFarmacia],
            ['email' => 'regente2@fartmar.test', 'name' => 'Regente Demo Dos', 'role' => Role::RegenteFarmacia],
            ['email' => 'auxiliar@fartmar.test', 'name' => 'Auxiliar Demo', 'role' => Role::AuxiliarFarmacia],
            ['email' => 'medico@fartmar.test', 'name' => 'Médico Demo', 'role' => Role::Medico],
            ['email' => 'auditor@fartmar.test', 'name' => 'Auditor Demo', 'role' => Role::Auditor],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'is_active' => true,
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
