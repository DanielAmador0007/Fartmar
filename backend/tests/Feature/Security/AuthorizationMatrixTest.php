<?php

/*
| Matriz de autorización rol × endpoint de la Fase 2 (CLAUDE.md §5).
| Permitido = cualquier respuesta distinta de 401/403 (el resultado de
| negocio lo prueban otros archivos); prohibido = 403 NO_AUTORIZADO.
| Al agregar endpoints (Fase 3/4) se amplía ENDPOINTS.
*/

use App\Domain\Dispensing\DispenseService;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

/**
 * endpoint => [roles permitidos, llamada]
 *
 * @return array<string, array{0: list<Role>, 1: Closure(DispensingScenario, int): TestResponse}>
 */
function authorizationEndpoints(): array
{
    $pharmacy = [Role::AuxiliarFarmacia, Role::RegenteFarmacia];

    return [
        'GET auth/me' => [Role::cases(), fn () => test()->getJson('/api/v1/auth/me')],
        'GET stocks' => [[...$pharmacy, Role::Auditor], fn () => test()->getJson('/api/v1/stocks')],
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
    ];
}

it('respeta la matriz de roles', function (Role $role, string $endpoint) {
    [$allowed, $call] = authorizationEndpoints()[$endpoint];

    // Controlado pendiente creado por el auxiliar del escenario (así el
    // regente que prueba es un "segundo usuario" y RN-05 no interfiere).
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = app(DispenseService::class)->create($s->auxiliar, $s->data(2), (string) Str::uuid())->dispensation;

    Sanctum::actingAs(User::factory()->role($role)->create());
    $response = $call($s, $pending->id);

    if (in_array($role, $allowed, true)) {
        expect($response->status())->not->toBeIn([401, 403], "{$role->value} debería poder usar {$endpoint}: ".$response->getContent());
    } else {
        $response->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
    }
})->with(fn () => collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => [$r]])->all())
    ->with(fn () => array_combine(array_keys(authorizationEndpoints()), array_keys(authorizationEndpoints())));

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
