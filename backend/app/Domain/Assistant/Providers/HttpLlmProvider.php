<?php

namespace App\Domain\Assistant\Providers;

use App\Domain\Assistant\LlmProvider;
use App\Domain\Exceptions\AssistantUnavailableException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base de los proveedores que llaman a un modelo por HTTP. Centraliza el
 * manejo de fallas: sin conexión, timeout, respuesta no 2xx o JSON
 * inválido -> AssistantUnavailableException (503). Al log va solo el
 * proveedor, el estado HTTP y la clase del error: nunca el cuerpo (trae la
 * conversación) ni los headers (traen la llave).
 */
abstract class HttpLlmProvider implements LlmProvider
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $body, array $headers = []): array
    {
        try {
            $response = $this->request()->withHeaders($headers)->post($url, $body);
        } catch (HttpClientException|GuzzleException $e) {
            // Sin conexión, timeout, RequestException u otra falla del cliente HTTP.
            $this->fail(null, $e::class);
        }

        if (! $response->successful()) {
            $this->fail($response, null);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $this->fail($response, 'invalid_json');
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) config('assistant.timeout', 30))
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();
    }

    protected function fail(?Response $response, ?string $error): never
    {
        Log::warning('assistant.provider_error', [
            'provider' => $this->name(),
            'status' => $response?->status(),
            'error' => $error,
        ]);

        throw AssistantUnavailableException::forProvider($this->name());
    }

    /**
     * Los argumentos de una herramienta pueden venir como objeto JSON o como
     * string con JSON adentro, según el modelo. Cualquier otra cosa -> [].
     *
     * @return array<string, mixed>
     */
    protected static function decodeArguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return is_array($arguments) ? $arguments : [];
    }
}
