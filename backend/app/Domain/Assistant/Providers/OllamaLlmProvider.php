<?php

namespace App\Domain\Assistant\Providers;

use App\Domain\Assistant\LlmResponse;
use App\Domain\Assistant\ToolCall;

/**
 * Modelo local con Ollama (LLM_PROVIDER=ollama): POST {OLLAMA_URL}/api/chat
 * con "tools" (formato function de OpenAI). Requiere un modelo con tool
 * calling, p. ej. qwen2.5:7b-instruct o llama3.1:8b (OLLAMA_MODEL).
 *
 * Los datos no salen de la máquina; a cambio, un modelo pequeño elige peor
 * las herramientas y necesita hardware (ver S-91).
 */
final class OllamaLlmProvider extends HttpLlmProvider
{
    public function name(): string
    {
        return 'ollama';
    }

    public function chat(array $messages, array $tools): LlmResponse
    {
        $json = $this->postJson(rtrim((string) config('assistant.ollama.url'), '/').'/api/chat', [
            'model' => (string) config('assistant.ollama.model'),
            'stream' => false,
            'messages' => array_map(self::toOllamaMessage(...), $messages),
            'tools' => array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ], $tools),
            'options' => [
                'temperature' => 0,
                'num_predict' => (int) config('assistant.max_tokens', 1024),
            ],
        ]);

        $message = $json['message'] ?? null;
        if (! is_array($message)) {
            $this->fail(null, 'unexpected_format');
        }

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $i => $call) {
            $name = $call['function']['name'] ?? null;
            if (is_string($name)) {
                // Ollama no asigna ids a las llamadas: se numeran.
                $calls[] = new ToolCall('ollama_'.$i, $name, self::decodeArguments($call['function']['arguments'] ?? []));
            }
        }

        return new LlmResponse(is_string($message['content'] ?? null) ? $message['content'] : null, $calls);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private static function toOllamaMessage(array $message): array
    {
        return match ($message['role']) {
            'assistant' => [
                'role' => 'assistant',
                'content' => (string) ($message['content'] ?? ''),
                'tool_calls' => array_map(fn (ToolCall $call) => [
                    'function' => ['name' => $call->name, 'arguments' => (object) $call->arguments],
                ], $message['tool_calls'] ?? []),
            ],
            'tool' => ['role' => 'tool', 'tool_name' => $message['name'], 'content' => $message['content']],
            default => ['role' => $message['role'], 'content' => $message['content']],
        };
    }
}
