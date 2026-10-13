<?php

namespace App\Domain\Assistant;

/**
 * Contrato de un proveedor de modelo de lenguaje (mock, Ollama, Anthropic).
 * Cambiar de modelo = cambiar LLM_PROVIDER; el resto de la aplicación solo
 * conoce esta interfaz.
 *
 * Mensajes en formato neutral (cada proveedor los traduce al suyo):
 *   ['role' => 'system',    'content' => string]
 *   ['role' => 'user',      'content' => string]
 *   ['role' => 'assistant', 'content' => string, 'tool_calls' => list<ToolCall>]
 *   ['role' => 'tool',      'tool_call_id' => string, 'name' => string, 'is_error' => bool, 'content' => string]
 *
 * Herramientas: ToolRegistry::definitions() (nombre, descripción, JSON Schema).
 *
 * Si el proveedor no responde o responde algo inválido, lanza
 * AssistantUnavailableException (503 ASISTENTE_NO_DISPONIBLE) sin detalles
 * del error externo.
 */
interface LlmProvider
{
    /** Nombre corto para la respuesta de la API y los logs (mock, ollama, anthropic). */
    public function name(): string;

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function chat(array $messages, array $tools): LlmResponse;
}
