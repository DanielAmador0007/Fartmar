<?php

/*
| Endurecimiento de la autenticación (revisión de seguridad Fase 2):
| expiración de tokens, usuarios inactivos con token vigente, tokens mal
| formados, límite de intentos de login y no enumeración de cuentas.
*/

use App\Domain\Auth\LoginService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

// CACHE_STORE=array (phpunit.xml): los contadores del limitador empiezan en
// cero en cada prueba.
uses(RefreshDatabase::class);

/** Login real por HTTP; devuelve el token Bearer. */
function loginToken(string $email, string $password = 'password'): string
{
    return (string) test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
        ->assertOk()
        ->json('token');
}

/**
 * Llama /auth/me con el token como si fuera una petición nueva: Laravel
 * reutiliza el guard entre peticiones de la misma prueba, así que se olvida
 * el usuario ya resuelto para que Sanctum vuelva a validar el token.
 */
function meWith(string $token): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/auth/me');
}

it('el token vence a los SANCTUM_EXPIRATION minutos (480 por defecto) y lo informa en el login', function () {
    User::factory()->auxiliar()->create(['email' => 'turno@fartmar.test']);

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'turno@fartmar.test', 'password' => 'password'])->assertOk();
    $token = (string) $response->json('token');

    expect(config('sanctum.expiration'))->toBe(480)
        ->and(abs(now()->addMinutes(480)->diffInSeconds($response->json('expires_at'))))->toBeLessThan(5);

    $this->travel(479)->minutes();
    meWith($token)->assertOk();

    $this->travel(2)->minutes();
    meWith($token)->assertUnauthorized()->assertJsonPath('error.code', 'NO_AUTENTICADO');
});

it('S-37: un usuario desactivado con token vigente ya no autentica en ningún endpoint', function () {
    $user = User::factory()->regente()->create(['email' => 'baja@fartmar.test']);
    $token = loginToken('baja@fartmar.test');
    meWith($token)->assertOk();

    $user->update(['is_active' => false]);

    meWith($token)->assertUnauthorized()->assertJsonPath('error.code', 'NO_AUTENTICADO');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/stocks')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

it('un token con id fuera de rango responde 401, no 500', function (string $token) {
    meWith($token)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'NO_AUTENTICADO');
})->with([
    'id mayor que bigint' => '99999999999999999999|abc',
    'id con ceros a la izquierda enorme' => '0000000000000000000000001|abc',
    'id vacío' => '|abc',
    'sin separador' => 'token-inventado',
]);

it('la API no acepta autenticación por cookie de sesión (solo Bearer)', function () {
    expect(config('sanctum.guard'))->toBe([]);
});

it('login: 5 intentos por minuto por correo + IP y luego 429 uniforme', function () {
    User::factory()->create(['email' => 'victima@fartmar.test']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'victima@fartmar.test', 'password' => 'mala'])
            ->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'victima@fartmar.test', 'password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'DEMASIADAS_SOLICITUDES')
        ->assertHeader('Retry-After');

    // Otra cuenta desde la misma IP aún puede intentar (el límite es por cuenta).
    User::factory()->create(['email' => 'otra@fartmar.test']);
    $this->postJson('/api/v1/auth/login', ['email' => 'otra@fartmar.test', 'password' => 'password'])->assertOk();
});

it('login: 20 intentos por minuto por IP aunque cambie el correo', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => "barrido{$i}@fartmar.test", 'password' => 'x'])
            ->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'barrido21@fartmar.test', 'password' => 'x'])
        ->assertStatus(429);
});

it('API autenticada: límite por usuario (por defecto 120/min) y luego 429 uniforme', function () {
    expect(config('fartmar.api_rate_limit_per_minute'))->toBe(120);
    config(['fartmar.api_rate_limit_per_minute' => 3]);
    $user = User::factory()->auxiliar()->create();
    Sanctum::actingAs($user);

    foreach (range(1, 3) as $_) {
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    $this->getJson('/api/v1/auth/me')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'DEMASIADAS_SOLICITUDES')
        ->assertHeader('Retry-After');

    // Otro usuario no se ve afectado (el límite es por usuario, no global).
    Sanctum::actingAs(User::factory()->auxiliar()->create());
    $this->getJson('/api/v1/auth/me')->assertOk();
});

it('login no revela si el correo existe ni si el usuario está inactivo', function () {
    User::factory()->create(['email' => 'existe@fartmar.test']);
    User::factory()->create(['email' => 'inactivo2@fartmar.test', 'is_active' => false]);

    $bodies = collect([
        ['email' => 'existe@fartmar.test', 'password' => 'incorrecta'],
        ['email' => 'noexiste@fartmar.test', 'password' => 'incorrecta'],
        ['email' => 'inactivo2@fartmar.test', 'password' => 'password'],
    ])->map(fn (array $credentials) => $this->postJson('/api/v1/auth/login', $credentials)
        ->assertUnauthorized()
        ->getContent());

    expect($bodies->unique())->toHaveCount(1);
});

/*
| Revisión de la Fase 4 (A1): enumeración de correos por tiempo.
*/

it('con el costo de producción (12) un correo inexistente no ejecuta Hash::make: usa el hash de relleno constante', function () {
    config(['hashing.bcrypt.rounds' => 12]);
    User::factory()->create(['email' => 'existe.tiempo@fartmar.test']);
    $proxy = Mockery::mock(app('hash')); // proxy: lo no esperado pasa al HashManager real
    $proxy->shouldNotReceive('make');
    Hash::swap($proxy);

    $this->postJson('/api/v1/auth/login', ['email' => 'nadie.tiempo@fartmar.test', 'password' => 'x'])->assertUnauthorized();

    expect(password_get_info(LoginService::dummyHash())['options']['cost'] ?? null)->toBe(12);
});

it('con otro costo el hash de relleno se calcula una sola vez (caché) y con ese mismo costo', function () {
    config(['hashing.bcrypt.rounds' => 5]);
    $real = app('hash');
    $proxy = Mockery::mock($real);
    $proxy->shouldReceive('make')->once()->andReturnUsing(fn (string $value) => $real->make($value));
    Hash::swap($proxy);

    foreach (['uno', 'dos', 'tres'] as $who) {
        $this->postJson('/api/v1/auth/login', ['email' => "{$who}.nadie@fartmar.test", 'password' => 'x'])->assertUnauthorized();
    }

    expect(password_get_info(LoginService::dummyHash())['options']['cost'] ?? null)->toBe(5);
});
