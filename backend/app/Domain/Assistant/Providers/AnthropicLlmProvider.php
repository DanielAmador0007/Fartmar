<?php

namespace App\Domain\Assistant\Providers;

use App\Domain\Assistant\AssistantMessages;
use App\Domain\Assistant\LlmResponse;
use App\Domain\Assistant\ToolCall;
use Illuminate\Support\Facades\Log;

/**
 * Modelo externo de Anthropic (LLM_PROVIDER=anthropic): Messages API con
 * tool use. Llave en LLM_API_KEY; modelo en ANTHROPIC_MODEL.
 *
 * Qué sale del servidor: el system prompt, la pregunta (solo si pasó
 * QuestionGuard, que rechaza las que hablan de pacientes) y los resultados
 * de herramientas de inventario. Nunca datos de pacientes (S-91).
 */
final class AnthropicLlmProvider extends HttpLlmProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function chat(array $messages, array $tools): LlmResponse
    {
        $apiKey = (string) config('assistant.anthropic.api_key');
        if ($apiKey === '') {
            $this->fail(null, 'missing_api_key');
        }

        $system = '';
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system .= $message['content'];
            }
        }

        $json = $this->postJson((string) config('assistant.anthropic.url'), [
            'model' => (string) config('assistant.anthropic.model'),
            'max_tokens' => (int) config('assistant.max_tokens', 1024),
            'system' => $system,
            'messages' => self::toAnthropicMessages($messages),
            'tools' => array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $tools),
        ], [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('assistant.anthropic.version'),
        ]);

        if (! is_array($json['content'] ?? null)) {
            $this->fail(null, 'unexpected_format');
        }

        // Cortada por max_tokens: el texto o un tool_use pueden venir a medias.
        // No se ejecuta nada y se responde un mensaje seguro.
        if (($json['stop_reason'] ?? null) === 'max_tokens') {
            Log::warning('assistant.incomplete_response', ['provider' => $this->name()]);

            return LlmResponse::text(AssistantMessages::INCOMPLETE);
        }

        $text = '';
        $calls = [];
        foreach ($json['content'] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? null) === 'tool_use' && is_string($block['name'] ?? null)) {
                $calls[] = new ToolCall((string) ($block['id'] ?? ''), $block['name'], self::decodeArguments($block['input'] ?? []));
            }
        }

        return new LlmResponse($text, $calls);
    }

    /**
     * Formato neutral -> Messages API:
     * - assistant con herramientas -> bloques text + tool_use;
     * - resultados de herramientas consecutivos -> UN mensaje user con
     *   bloques tool_result (la API exige que sigan al tool_use).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function toAnthropicMessages(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                continue;
            }

            if ($message['role'] === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => $message['tool_call_id'], 'content' => $message['content']];
                if ($message['is_error'] ?? false) {
                    $block['is_error'] = true;
                }
                $last = array_key_last($result);

                if ($last !== null && $result[$last]['role'] === 'user' && is_array($result[$last]['content'])) {
                    $result[$last]['content'][] = $block;
                } else {
                    $result[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($message['role'] === 'assistant' && ($message['tool_calls'] ?? []) !== []) {
                $blocks = [];
                if (trim((string) $message['content']) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $message['content']];
                }
                foreach ($message['tool_calls'] as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => (object) $call->arguments];
                }
                $result[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $result[] = ['role' => $message['role'], 'content' => (string) $message['content']];
        }

        return $result;
    }
}
