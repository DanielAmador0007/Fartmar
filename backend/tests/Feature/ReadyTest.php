<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('responde 200 en /ready con la base de datos migrada', function () {
    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'migrations' => 'ok'],
        ]);
});

it('responde 503 en /ready si hay migraciones pendientes', function () {
    // Registra una ruta extra con una migración que nunca se ejecutó.
    $dir = sys_get_temp_dir().'/fartmar-pending-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/2999_01_01_000000_pending_migration.php', '<?php return null;');
    app(Migrator::class)->path($dir);

    try {
        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.migrations', 'pending');
    } finally {
        unlink($dir.'/2999_01_01_000000_pending_migration.php');
        rmdir($dir);
    }
});
