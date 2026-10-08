<?php

/*
| Endurecimiento de la API (revisión de seguridad Fase 2): ids fuera de
| rango, errores 500 sin detalles internos, payload estricto y CORS.
*/

use App\Models\Dispensation;
use App\Models\KardexMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

it('un id de dispensación fuera de rango responde 404, no 500 con SQL', function (string $id) {
    $s = DispensingScenario::make();
    Sanctum::actingAs($s->auxiliar);

    $this->getJson("/api/v1/dispensations/{$id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NO_ENCONTRADO');
    $this->postJson("/api/v1/dispensations/{$id}/authorize")->assertNotFound();
})->with([
    'mayor que bigint' => '99999999999999999999',
    'cero' => '0',
    'ceros a la izquierda' => '000001',
]);

it('con APP_DEBUG=false un error inesperado responde 500 uniforme sin SQL ni datos de conexión', function () {
    config(['app.debug' => false]);
    Route::middleware('api')->get('/api/v1/_prueba/falla', fn () => DB::select('select * from tabla_que_no_existe'));

    $response = $this->getJson('/api/v1/_prueba/falla')
        ->assertStatus(500)
        ->assertExactJson(['error' => [
            'code' => 'ERROR_INTERNO',
            'message' => 'Ocurrió un error inesperado. Intente de nuevo o contacte a soporte.',
            'details' => [],
        ]]);

    $body = (string) $response->getContent();
    foreach (['SQLSTATE', 'tabla_que_no_existe', 'fartmar_test', 'pgsql', 'trace', 'vendor/'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('rechaza items como objeto o con claves extra (payload estricto) y no dispensa', function (string $case) {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $payload = $s->payload(1);
    $line = $payload['items'][0];

    $payload['items'] = match ($case) {
        'items como objeto' => ['a' => $line],
        'línea con clave extra' => [[...$line, 'lot_id' => 1]],
        'línea que no es objeto' => [5],
        'id negativo' => [['prescription_item_id' => -1, 'quantity' => 1]],
        'id fuera de rango' => [['prescription_item_id' => '99999999999999999999', 'quantity' => 1]],
    };

    $this->postJson('/api/v1/dispensations', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION');

    expect(Dispensation::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->totalStock())->toBe(10);
})->with(['items como objeto', 'línea con clave extra', 'línea que no es objeto', 'id negativo', 'id fuera de rango']);

// Con un solo origen permitido el paquete CORS siempre responde con ESE origen;
// el navegador bloquea porque no coincide con el del sitio malicioso.
it('CORS: solo el origen del frontend queda autorizado (no se refleja cualquier origen)', function () {
    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    expect($preflight('http://localhost:5173')->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and($preflight('https://sitio-malicioso.example')->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://sitio-malicioso.example')
        ->not->toBe('*');
});
