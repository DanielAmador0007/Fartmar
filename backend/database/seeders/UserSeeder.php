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

        // firstOrCreate, no updateOrCreate: el servicio migrate siembra en cada
        // `up` y no debe devolver la clave "password", el rol ni la activación a
        // un usuario demo que el admin ya cambió (S-111). `make fresh` sí los
        // recrea (borra la BD).
        foreach ($users as $user) {
            User::query()->firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'is_active' => true,
                    // Demo: entran directo con "password" (S-77 no aplica a la semilla).
                    'must_change_password' => false,
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
