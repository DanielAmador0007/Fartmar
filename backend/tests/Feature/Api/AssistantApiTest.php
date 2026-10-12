<?php

/*
| POST /api/v1/assistant/query (Parte C). Proveedor mock por defecto.
| Los permisos por rol también están en AuthorizationMatrixTest.
*/

use App\Domain\Assistant\LlmProvider;
use App\Domain\Assistant\LlmResponse;
use App\Domain\Exceptions\AssistantUnavailableException;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('responde con answer, outcome, provider, tools_used y result', function () {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $response = $this->postJson('/api/v1/assistant/query', [
        'question' => '¿Qué lotes de acetaminofén vencen en los próximos 60 días en la Farmacia Central?',
    ])->assertOk()->assertHeader('X-Correlation-Id');

    $response->assertJsonPath('data.outcome', 'answered')
        ->assertJsonPath('data.provider', 'mock')
        ->assertJsonPath('data.tools_used.0.name', 'search_products')
        ->assertJsonPath('data.tools_used.0.arguments.query', 'acetaminofen')
        ->assertJsonPath('data.tools_used.1.name', 'get_expiring_lots')
        ->assertJsonPath('data.tools_used.1.arguments.days', 60)
        ->assertJsonPath('data.tools_used.1.ok', true)
        ->assertJsonPath('data.result.tool', 'get_expiring_lots')
        ->assertJsonPath('data.result.rows.0.lot_number', 'L-ACE-2402')
        ->assertJsonPath('data.result.rows.0.warehouse.code', 'FAR-CEN');

    expect($response->json('data.answer'))->toContain('L-ACE-2402');
});

it('las herramientas sin argumentos devuelven arguments como objeto JSON', function () {
    Sanctum::actingAs(User::factory()->regente()->create());

    $response = $this->postJson('/api/v1/assistant/query', ['question' => '¿Qué bodegas existen?'])->assertOk();

    expect($response->getContent())->toContain('"arguments":{}')
        ->and($response->json('data.result.rows'))->toHaveCount(3);
});

it('el auditor puede usar el asistente (solo lectura de inventario)', function () {
    Sanctum::actingAs(User::factory()->auditor()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => '¿Qué lotes están vencidos?'])
        ->assertOk()
        ->assertJsonPath('data.result.rows.0.lot_number', 'L-ACE-2401');
});

it('médico y admin no pueden usar el asistente', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => '¿Cuánto stock hay?'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');
})->with(['medico', 'admin']);

it('valida la pregunta', function (mixed $question) {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => $question])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION');
})->with([
    'vacía' => [''],
    'muy corta' => ['ab'],
    'muy larga' => [str_repeat('a', 501)],
    'no es texto' => [['stock']],
]);

it('una pregunta sobre pacientes responde 200 con outcome refused', function () {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => 'Dame la cédula de la paciente Ana'])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'refused')
        ->assertJsonPath('data.tools_used', [])
        ->assertJsonPath('data.result', null);
});

it('tiene su propio límite por minuto', function () {
    config(['assistant.rate_limit_per_minute' => 2]);
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => '¿Qué bodegas existen?'])->assertOk();
    $this->postJson('/api/v1/assistant/query', ['question' => '¿Qué bodegas existen?'])->assertOk();
    $this->postJson('/api/v1/assistant/query', ['question' => '¿Qué bodegas existen?'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'DEMASIADAS_SOLICITUDES');
});

it('si el proveedor no está disponible responde 503 ASISTENTE_NO_DISPONIBLE sin detalles técnicos', function () {
    app()->bind(LlmProvider::class, fn () => new class implements LlmProvider
    {
        public function name(): string
        {
            return 'roto';
        }

        public function chat(array $messages, array $tools): LlmResponse
        {
            throw AssistantUnavailableException::forProvider('roto');
        }
    });
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => '¿Cuánto stock hay?'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'ASISTENTE_NO_DISPONIBLE')
        ->assertJsonPath('error.details', ['provider' => 'roto']);
});

it('un LLM_PROVIDER desconocido responde 503, no 500', function () {
    config(['assistant.provider' => 'gpt-imaginario']);
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->postJson('/api/v1/assistant/query', ['question' => '¿Cuánto stock hay?'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'ASISTENTE_NO_DISPONIBLE');
});
