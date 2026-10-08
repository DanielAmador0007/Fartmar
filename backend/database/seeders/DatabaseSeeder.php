<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos semilla SINTÉTICOS (enunciado §6). Todos los seeders son idempotentes:
 * `php artisan db:seed` puede ejecutarse varias veces sin duplicar nada.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CatalogSeeder::class,
            InventorySeeder::class,
            PatientSeeder::class,
        ]);
    }
}
