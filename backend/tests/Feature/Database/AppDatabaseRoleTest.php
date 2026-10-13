<?php

/*
| Rol de BD de la aplicación en producción (Fase 7, S-101): no es dueño de las
| tablas, no puede TRUNCATE (los triggers de inmutabilidad no lo bloquean) y
| en kardex y bitácoras solo puede leer e insertar.
|
| RefreshDatabase envuelve todo en una transacción: CREATE ROLE, GRANT y
| ALTER DEFAULT PRIVILEGES son transaccionales en PostgreSQL, así que el rol
| de prueba desaparece al terminar cada prueba.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TEST_APP_ROLE = 'fartmar_app_role_test';

function appRoleCan(string $table, string $privilege): bool
{
    return (bool) DB::selectOne(
        'select has_table_privilege(?, ?, ?) as allowed',
        [TEST_APP_ROLE, "public.{$table}", $privilege],
    )->allowed;
}

beforeEach(function () {
    config([
        'fartmar.db_app_role.username' => TEST_APP_ROLE,
        'fartmar.db_app_role.password' => 'clave-de-prueba-larga',
    ]);
});

it('crea un rol sin DDL ni TRUNCATE y con kardex y bitácoras de solo inserción', function () {
    $this->artisan('db:provision-app-role')->assertSuccessful();

    $role = DB::selectOne(
        'select rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls from pg_roles where rolname = ?',
        [TEST_APP_ROLE],
    );
    expect($role->rolcanlogin)->toBeTrue()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();

    // Tablas operativas: CRUD sin TRUNCATE.
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        expect(appRoleCan('stocks', $privilege))->toBeTrue("stocks {$privilege}");
    }
    expect(appRoleCan('stocks', 'TRUNCATE'))->toBeFalse()
        ->and(appRoleCan('stocks', 'TRIGGER'))->toBeFalse();

    // Solo inserción.
    foreach (['kardex_movements', 'audit_logs', 'patient_access_logs'] as $table) {
        expect(appRoleCan($table, 'SELECT'))->toBeTrue("{$table} SELECT")
            ->and(appRoleCan($table, 'INSERT'))->toBeTrue("{$table} INSERT")
            ->and(appRoleCan($table, 'UPDATE'))->toBeFalse("{$table} UPDATE")
            ->and(appRoleCan($table, 'DELETE'))->toBeFalse("{$table} DELETE")
            ->and(appRoleCan($table, 'TRUNCATE'))->toBeFalse("{$table} TRUNCATE");
    }

    // Sin CREATE en el esquema y sin ser dueño de nada.
    $canCreate = DB::selectOne("select has_schema_privilege(?, 'public', 'CREATE') as allowed", [TEST_APP_ROLE])->allowed;
    $owned = DB::selectOne(
        "select count(*) as total from pg_tables where schemaname = 'public' and tableowner = ?",
        [TEST_APP_ROLE],
    )->total;
    expect($canCreate)->toBeFalse()->and((int) $owned)->toBe(0);
});

it('las tablas que creen migraciones futuras quedan con permisos para el rol', function () {
    $this->artisan('db:provision-app-role')->assertSuccessful();

    DB::statement('create table future_feature_test (id bigserial primary key)');

    expect(appRoleCan('future_feature_test', 'INSERT'))->toBeTrue()
        ->and(appRoleCan('future_feature_test', 'TRUNCATE'))->toBeFalse();
});

it('es idempotente', function () {
    $this->artisan('db:provision-app-role')->assertSuccessful();
    $this->artisan('db:provision-app-role')->assertSuccessful();

    expect((int) DB::selectOne('select count(*) as total from pg_roles where rolname = ?', [TEST_APP_ROLE])->total)->toBe(1);
});

it('sin DB_APP_USERNAME no crea nada (desarrollo)', function () {
    config(['fartmar.db_app_role.username' => null]);

    $this->artisan('db:provision-app-role')->assertSuccessful();

    expect(DB::selectOne('select 1 as found from pg_roles where rolname = ?', [TEST_APP_ROLE]))->toBeNull();
});

it('rechaza un nombre de rol inválido o una clave corta', function (array $override) {
    config($override);

    $this->artisan('db:provision-app-role')->assertFailed();
})->with([
    'nombre con comillas' => [['fartmar.db_app_role.username' => 'app"; drop table stocks; --']],
    'clave corta' => [['fartmar.db_app_role.password' => 'corta']],
]);

it('gestionado externamente: falla claro si el rol no existe y no lo crea (M3)', function () {
    config(['fartmar.db_app_role.managed_externally' => true, 'fartmar.db_app_role.password' => null]);

    $this->artisan('db:provision-app-role')
        ->expectsOutputToContain('no existe')
        ->assertFailed();

    expect(DB::selectOne('select 1 as found from pg_roles where rolname = ?', [TEST_APP_ROLE]))->toBeNull();
});

it('gestionado externamente: solo aplica GRANT/REVOKE, sin ALTER ROLE ni clave (M3)', function () {
    DB::statement('CREATE ROLE '.TEST_APP_ROLE.' LOGIN');
    config(['fartmar.db_app_role.managed_externally' => true, 'fartmar.db_app_role.password' => null]);

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->artisan('db:provision-app-role')->assertSuccessful();

    expect(collect($statements)->filter(fn (string $sql) => str_contains($sql, 'ALTER ROLE') || str_contains($sql, 'CREATE ROLE')))->toBeEmpty()
        ->and(appRoleCan('stocks', 'UPDATE'))->toBeTrue()
        ->and(appRoleCan('stocks', 'TRUNCATE'))->toBeFalse()
        ->and(appRoleCan('kardex_movements', 'UPDATE'))->toBeFalse();
});

it('si una sentencia falla, la clave no aparece en la excepción (B10)', function () {
    // pg_monitor es un rol reservado de PostgreSQL: ALTER ROLE ... PASSWORD falla.
    config([
        'fartmar.db_app_role.username' => 'pg_monitor',
        'fartmar.db_app_role.password' => 'clave-que-no-debe-filtrarse',
    ]);

    try {
        $this->artisan('db:provision-app-role');
        test()->fail('Se esperaba una excepción.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('clave-que-no-debe-filtrarse')
            ->and($e->getMessage())->not->toContain('PASSWORD')
            ->and($e->getMessage())->toContain('SQLSTATE')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('rechaza usar como rol de la app el mismo usuario que migra', function () {
    $owner = (string) DB::selectOne('select current_user as name')->name;
    config(['fartmar.db_app_role.username' => $owner]);

    $this->artisan('db:provision-app-role')->assertFailed();
});
