<?php

use Illuminate\Support\Facades\DB;

it('responde 200 en /health sin autenticación', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('no consulta la base de datos en /health (liveness)', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->getJson('/health')->assertOk();

    expect($queries)->toBe(0);
});

it('responde 503 en /ready cuando la base de datos no está disponible', function () {
    // Puerto cerrado en localhost: la conexión falla de inmediato.
    config([
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => 1,
    ]);
    DB::purge('pgsql');

    $this->getJson('/ready')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'unavailable',
            'checks' => ['database' => 'fail', 'migrations' => 'unknown'],
        ]);
});
