<?php

/*
| Autenticación con tokens de Sanctum: login, me, logout.
*/

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('login devuelve un token y el usuario sin datos sensibles', function () {
    $user = User::factory()->regente()->create(['email' => 'regente.test@fartmar.test']);

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'regente.test@fartmar.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'regente_farmacia')
        ->assertJsonMissingPath('user.password');

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'regente.test@fartmar.test');
});

it('rechaza credenciales inválidas con el formato de error uniforme', function () {
    User::factory()->create(['email' => 'aux@fartmar.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'aux@fartmar.test', 'password' => 'otra'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'CREDENCIALES_INVALIDAS');

    $this->postJson('/api/v1/auth/login', ['email' => 'noexiste@fartmar.test', 'password' => 'password'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'CREDENCIALES_INVALIDAS');
});

it('un usuario inactivo no puede iniciar sesión', function () {
    User::factory()->create(['email' => 'inactivo@fartmar.test', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', ['email' => 'inactivo@fartmar.test', 'password' => 'password'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'CREDENCIALES_INVALIDAS');
});

it('logout revoca el token usado', function () {
    $user = User::factory()->create(['email' => 'salir@fartmar.test']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'salir@fartmar.test', 'password' => 'password'])->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('sin token responde 401 con el formato uniforme', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertExactJson(['error' => ['code' => 'NO_AUTENTICADO', 'message' => 'Debe iniciar sesión para continuar.', 'details' => []]]);
});

it('login valida los campos', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password']]]]);
});
