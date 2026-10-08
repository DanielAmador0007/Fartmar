<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Todas las pruebas Feature y Concurrency usan el TestCase de Laravel.
| La base de datos de pruebas es PostgreSQL (fartmar_test), ver phpunit.xml.
| Cada archivo declara su propio trait de BD; la prueba de concurrencia NO debe
| usar RefreshDatabase (la transacción envolvente ocultaría el problema).
*/
pest()->extend(TestCase::class)->in('Feature', 'Concurrency');

/**
 * Ejecuta $statement y exige que PostgreSQL lo rechace con un mensaje que
 * contenga $expected (normalmente el nombre del constraint o del trigger).
 *
 * Corre dentro de DB::transaction(): con RefreshDatabase eso es un SAVEPOINT,
 * así que tras el error la transacción de la prueba sigue usable y se pueden
 * verificar varias violaciones en la misma prueba.
 */
function expectDbRejects(Closure $statement, string $expected): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($expected);

        return;
    }

    test()->fail("La base de datos aceptó una operación que debía rechazar ({$expected}).");
}
