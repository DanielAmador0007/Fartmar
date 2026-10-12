<?php

/*
| Matriz de autorización rol × endpoint de las Fases 2, 3 y 4 (CLAUDE.md §5)
| más el caso "sin autenticar" (401).
| Permitido = cualquier respuesta distinta de 401/403 (el resultado de
| negocio lo prueban otros archivos); prohibido = 403 NO_AUTORIZADO.
| Al agregar un endpoint se amplía authorizationEndpoints(); una prueba
| falla si alguna ruta /api/v1 queda fuera de la matriz.
*/

use App\Domain\Dispensing\DispenseService;
use App\Domain\Transfers\TransferService;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\PatientAccessLog;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItem;
use App\Models\TransferItemLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

/**
 * endpoint => [roles permitidos, llamada]
 *
 * @return array<string, array{0: list<Role>, 1: Closure(DispensingScenario, int, User): TestResponse}>
 */
function authorizationEndpoints(): array
{
    $pharmacy = [Role::AuxiliarFarmacia, Role::RegenteFarmacia];
    // PatientPolicy (S-68): todos menos admin; el auditor ve enmascarado.
    $patientReaders = [...$pharmacy, Role::Medico, Role::Auditor];

    return [
        'GET auth/me' => [Role::cases(), fn () => test()->getJson('/api/v1/auth/me')],
        // S-77: cualquier usuario cambia SU contraseña (la de la factory es "password").
        'POST auth/password' => [Role::cases(), fn () => test()->postJson('/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'ClaveNueva2026', 'password_confirmation' => 'ClaveNueva2026',
        ])],
        'GET stocks' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/stocks')],
        'GET stocks/in-transit' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/stocks/in-transit')],
        'GET dispensations/preview' => [$pharmacy, fn (DispensingScenario $s) => test()->getJson(
            "/api/v1/dispensations/preview?warehouse_id={$s->warehouse->id}&product_id={$s->product->id}&quantity=1",
        )],
        'POST dispensations' => [$pharmacy, fn (DispensingScenario $s) => test()->postJson(
            '/api/v1/dispensations', $s->payload(1), ['Idempotency-Key' => (string) Str::uuid()],
        )],
        'GET dispensations/{id}' => [[...$pharmacy, Role::Auditor], fn (DispensingScenario $s, int $id) => test()->getJson("/api/v1/dispensations/{$id}")],
        'POST dispensations/{id}/authorize' => [[Role::RegenteFarmacia], fn (DispensingScenario $s, int $id) => test()->postJson("/api/v1/dispensations/{$id}/authorize")],
        'POST dispensations/{id}/reject' => [[Role::RegenteFarmacia], fn (DispensingScenario $s, int $id) => test()->postJson(
            "/api/v1/dispensations/{$id}/reject", ['reason' => 'Fórmula ilegible'],
        )],

        // Fase 3: traslados, discrepancias, ajustes, kardex y alertas.
        'GET transfers' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/transfers')],
        'POST transfers' => [$pharmacy, fn () => test()->postJson('/api/v1/transfers', TransferScenario::make()->payload())],
        'GET transfers/{id}' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/transfers/'.TransferScenario::make()->requested()->id)],
        // Solo quien creó el borrador (S-49): el borrador es del usuario que prueba.
        'POST transfers/{id}/submit' => [$pharmacy, fn (DispensingScenario $s, int $id, User $actor) => test()->postJson(
            '/api/v1/transfers/'.draftTransferOf($actor)->id.'/submit',
        )],
        // Solicitado por otro usuario, así RN-08 no interfiere.
        'POST transfers/{id}/approve' => [[Role::RegenteFarmacia], fn () => test()->postJson(
            '/api/v1/transfers/'.TransferScenario::make()->requested()->id.'/approve',
        )],
        'POST transfers/{id}/dispatch' => [$pharmacy, fn () => test()->postJson(
            '/api/v1/transfers/'.TransferScenario::make()->approved()->id.'/dispatch',
        )],
        'POST transfers/{id}/receive' => [$pharmacy, function () {
            $transfer = TransferScenario::make()->dispatched(2);
            $lot = TransferItemLot::query()->sole();

            return test()->postJson("/api/v1/transfers/{$transfer->id}/receive", ['lots' => [
                ['transfer_item_lot_id' => $lot->id, 'quantity_received' => 2],
            ]]);
        }],
        // Quien lo creó o un regente: el borrador es del usuario que prueba.
        'POST transfers/{id}/cancel' => [$pharmacy, fn (DispensingScenario $s, int $id, User $actor) => test()->postJson(
            '/api/v1/transfers/'.draftTransferOf($actor)->id.'/cancel', ['reason' => 'Ya no se necesita'],
        )],
        'GET transfer-discrepancies' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/transfer-discrepancies')],
        'POST transfer-discrepancies/{id}/resolve' => [[Role::RegenteFarmacia], function () {
            $t = TransferScenario::make();
            $transfer = $t->dispatched(2);
            app(TransferService::class)->receive($transfer, $t->auxiliar, [TransferItemLot::query()->sole()->id => 0]);

            return test()->postJson('/api/v1/transfer-discrepancies/'.TransferDiscrepancy::query()->sole()->id.'/resolve', [
                'action' => 'PERDIDA', 'resolution' => 'Caja rota en el camino',
            ]);
        }],
        'POST inventory-adjustments' => [[Role::RegenteFarmacia], fn (DispensingScenario $s) => test()->postJson('/api/v1/inventory-adjustments', [
            'warehouse_id' => $s->warehouse->id, 'lot_id' => $s->lots[0]->id, 'quantity' => 1, 'reason' => 'Conteo físico',
        ])],
        'GET kardex' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/kardex')],
        'GET alerts' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/alerts')],

        // Fase 4: pacientes (RN-10), prescripciones y usuarios.
        'POST patients/search' => [$patientReaders, fn (DispensingScenario $s) => test()->postJson('/api/v1/patients/search', [
            'document_type' => $s->patient->document_type, 'document_number' => $s->patient->document_number,
        ])],
        'GET patients/{id}' => [$patientReaders, fn (DispensingScenario $s) => test()->getJson("/api/v1/patients/{$s->patient->id}")],
        'GET patients/{id}/prescriptions' => [$patientReaders, fn (DispensingScenario $s) => test()->getJson("/api/v1/patients/{$s->patient->id}/prescriptions")],
        'POST prescriptions' => [[Role::Medico], fn (DispensingScenario $s) => test()->postJson('/api/v1/prescriptions', [
            'patient_id' => $s->patient->id,
            'valid_until' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $s->product->id, 'quantity' => 1]],
        ])],
        // Fase 5: catálogos de solo lectura (todos los roles, S-86).
        'GET warehouses' => [Role::cases(), fn () => test()->getJson('/api/v1/warehouses')],
        'GET products' => [Role::cases(), fn () => test()->getJson('/api/v1/products?q=med')],

        'GET users' => [[Role::Admin], fn () => test()->getJson('/api/v1/users')],
        'POST users' => [[Role::Admin], fn () => test()->postJson('/api/v1/users', [
            'name' => 'Usuario Matriz', 'email' => 'matriz.'.Str::lower(Str::random(8)).'@fartmar.test',
            'password' => 'ClaveSegura2026', 'role' => 'auxiliar_farmacia',
        ])],
        'PATCH users/{id}' => [[Role::Admin], fn (DispensingScenario $s) => test()->patchJson(
            "/api/v1/users/{$s->auxiliar->id}", ['name' => 'Nombre Cambiado'],
        )],
    ];
}

/**
 * Borrador de traslado cuyo solicitante es $user (sin pasar por el servicio,
 * para poder crearlo también a nombre de roles que no pueden crear).
 */
function draftTransferOf(User $user): Transfer
{
    $t = TransferScenario::make();
    $transfer = Transfer::query()->create([
        'origin_warehouse_id' => $t->origin->id,
        'destination_warehouse_id' => $t->destination->id,
        'status' => TransferStatus::Borrador,
        'requested_by' => $user->id,
    ]);
    TransferItem::query()->create(['transfer_id' => $transfer->id, 'product_id' => $t->product->id, 'quantity_requested' => 1]);

    return $transfer;
}

it('respeta la matriz de roles', function (Role $role, string $endpoint) {
    [$allowed, $call] = authorizationEndpoints()[$endpoint];

    // Controlado pendiente creado por el auxiliar del escenario (así el
    // regente que prueba es un "segundo usuario" y RN-05 no interfiere).
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = app(DispenseService::class)->create($s->auxiliar, $s->data(2), (string) Str::uuid())->dispensation;

    $actor = User::factory()->role($role)->create();
    Sanctum::actingAs($actor);
    $response = $call($s, $pending->id, $actor);

    if (in_array($role, $allowed, true)) {
        expect($response->status())->not->toBeIn([401, 403], "{$role->value} debería poder usar {$endpoint}: ".$response->getContent());
    } else {
        $response->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
        // RN-10: una lectura negada no cuenta como acceso al paciente.
        expect(PatientAccessLog::query()->count())->toBe(0);
    }
})->with(fn () => collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => [$r]])->all())
    ->with(fn () => array_combine(array_keys(authorizationEndpoints()), array_keys(authorizationEndpoints())));

it('sin autenticación todos los endpoints de la matriz responden 401 y no registran accesos', function (string $endpoint) {
    [, $call] = authorizationEndpoints()[$endpoint];
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = app(DispenseService::class)->create($s->auxiliar, $s->data(2), (string) Str::uuid())->dispensation;

    // $actor solo da nombre a los borradores; nadie inicia sesión.
    $call($s, $pending->id, User::factory()->auxiliar()->create())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'NO_AUTENTICADO');

    expect(PatientAccessLog::query()->count())->toBe(0);
})->with(fn () => array_combine(array_keys(authorizationEndpoints()), array_keys(authorizationEndpoints())));

it('la matriz cubre todas las rutas /api/v1 salvo login y logout', function () {
    $covered = collect(array_keys(authorizationEndpoints()))
        ->map(fn (string $key) => preg_replace('/\{[^}]+\}/', '{}', $key))
        ->all();

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && ! in_array($route->getName(), ['auth.login', 'auth.logout'], true))
        ->map(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '
            .preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), strlen('api/v1/'))));

    expect($routes->count())->toBeGreaterThan(20);
    foreach ($routes as $route) {
        expect($covered)->toContain($route);
    }
});

it('todas las rutas /api/v1 exigen auth:sanctum salvo el login', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        if ($route->getName() === 'auth.login') {
            expect($middleware)->toContain('throttle:login');

            continue;
        }
        expect(in_array('auth:sanctum', $middleware, true))->toBeTrue("La ruta {$route->uri()} no exige autenticación.");
    }
});
