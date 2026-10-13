<?php

/*
| IP del cliente detrás de Nginx (Fase 7, S-100). Con FastCGI, Nginx entrega
| REMOTE_ADDR = cliente; un X-Forwarded-For enviado por el cliente no debe
| cambiar la IP que usan los limitadores y las bitácoras.
*/

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/client-ip', fn (Request $request) => response()->json(['ip' => $request->ip()]));
});

it('por defecto ignora X-Forwarded-For (no confía en ningún proxy)', function () {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->getJson('/_test/client-ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertExactJson(['ip' => '172.18.0.5']);
});

it('con TRUSTED_PROXIES toma la IP del cliente que informa el proxy de confianza', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->getJson('/_test/client-ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertExactJson(['ip' => '203.0.113.9']);
});

it('un X-Forwarded-For desde una IP que no es proxy de confianza se ignora', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->getJson('/_test/client-ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertExactJson(['ip' => '198.51.100.7']);
});
