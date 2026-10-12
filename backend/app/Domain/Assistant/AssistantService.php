<?php

namespace App\Domain\Assistant;

use App\Domain\Exceptions\AssistantUnavailableException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Asistente de consulta de inventario en lenguaje natural (Parte C).
 *
 * Flujo de ask():
 *   1. QuestionGuard: pacientes, SQL, escritura o inyección -> rechazo fijo
 *      sin llamar al modelo.
 *   2. Bucle (máx. assistant.max_iterations llamadas al modelo):
 *      - el modelo responde texto -> fin;
 *      - o pide herramientas -> ToolRegistry las valida, autoriza con el
 *        usuario autenticado y ejecuta (solo lectura); cada resultado vuelve
 *        al modelo como JSON dentro de <tool_result>…</tool_result>.
 *   3. OutputGuard: si la respuesta trae algo prohibido, se reemplaza.
 *
 * La autorización la decide el servidor (Policies), nunca el modelo. No se
 * envían datos de pacientes al modelo: ninguna herramienta los devuelve.
 * Al log solo van metadatos (proveedor, herramientas, duración); nunca la
 * pregunta ni la respuesta, que podrían traer datos personales escritos por
 * el usuario.
 */
final class AssistantService
{
    /** Herramientas auxiliares: su resultado no es "la respuesta" para la tabla de la UI. */
    private const HELPER_TOOLS = ['search_products'];

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $tools,
        private readonly SystemPrompt $systemPrompt,
    ) {}

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function ask(User $user, string $question): AssistantAnswer
    {
        $started = hrtime(true);

        try {
            $answer = $this->run($user, trim($question));
        } catch (AssistantUnavailableException $e) {
            Log::warning('assistant.query_failed', [
                'provider' => $this->provider->name(),
                'duration_ms' => self::elapsedMs($started),
            ]);

            throw $e;
        }

        Log::info('assistant.query', [
            'provider' => $answer->provider,
            'outcome' => $answer->outcome,
            'tools' => array_column($answer->toolsUsed, 'name'),
            'iterations' => $answer->iterations,
            'duration_ms' => self::elapsedMs($started),
        ]);

        return $answer;
    }

    private function run(User $user, string $question): AssistantAnswer
    {
        $reason = QuestionGuard::reasonFor($question);
        if ($reason !== null) {
            return new AssistantAnswer(QuestionGuard::refusalFor($reason), AssistantAnswer::REFUSED, $this->provider->name());
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt->build()],
            ['role' => 'user', 'content' => $question],
        ];
        $definitions = $this->tools->definitions();
        $toolsUsed = [];
        $result = null;
        $maxIterations = (int) config('assistant.max_iterations', 4);

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            $response = $this->provider->chat($messages, $definitions);

            if (! $response->hasToolCalls()) {
                return $this->finish((string) $response->text, $toolsUsed, $result, $iteration);
            }

            $calls = array_slice($response->toolCalls, 0, (int) config('assistant.max_tool_calls_per_turn', 5));
            $messages[] = ['role' => 'assistant', 'content' => (string) $response->text, 'tool_calls' => $calls];

            foreach ($calls as $call) {
                $toolResult = $this->tools->execute($user, $call);

                // A la API solo van los argumentos validados (los que se usaron);
                // los que inventó el modelo para una herramienta rechazada, no.
                $toolsUsed[] = [
                    'name' => mb_substr($call->name, 0, 64),
                    'arguments' => $toolResult->ok ? self::clip($toolResult->arguments) : [],
                    'ok' => $toolResult->ok,
                ];
                if ($toolResult->ok && ! in_array($call->name, self::HELPER_TOOLS, true)) {
                    $result = ['tool' => $call->name, ...$toolResult->data];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => self::wrapToolResult($call->name, $toolResult),
                ];
            }
        }

        return $this->finish(AssistantMessages::TOO_MANY_STEPS, $toolsUsed, $result, $maxIterations);
    }

    /**
     * Serializa el resultado como DATO para el modelo. JSON_HEX_TAG convierte
     * < y > en < y >: un texto malicioso no puede cerrar la
     * etiqueta y "salirse" del bloque de datos.
     */
    public static function wrapToolResult(string $name, ToolResult $result): string
    {
        $safeName = (string) preg_replace('/[^A-Za-z0-9_]/', '', $name);
        $json = json_encode($result->toPayload(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        return '<tool_result name="'.$safeName.'">'.$json.'</tool_result>';
    }

    /**
     * Lee de vuelta el JSON de un <tool_result> (lo usa el proveedor mock).
     *
     * @return array<string, mixed>|null
     */
    public static function unwrapToolResult(string $content): ?array
    {
        if (preg_match('/^<tool_result name="[A-Za-z0-9_]*">(.*)<\/tool_result>$/s', $content, $m) !== 1) {
            return null;
        }

        $decoded = json_decode($m[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, ok: bool}>  $toolsUsed
     * @param  array<string, mixed>|null  $result
     */
    private function finish(string $text, array $toolsUsed, ?array $result, int $iterations): AssistantAnswer
    {
        $text = mb_substr(trim($text), 0, (int) config('assistant.max_answer_length', 2000));

        if ($text === '') {
            $text = AssistantMessages::OUT_OF_SCOPE;
        }

        if (! OutputGuard::isSafe($text)) {
            Log::warning('assistant.output_blocked', ['provider' => $this->provider->name()]);

            return new AssistantAnswer(AssistantMessages::OUTPUT_BLOCKED, AssistantAnswer::REFUSED, $this->provider->name(), $toolsUsed, null, $iterations);
        }

        $outcome = $toolsUsed === [] && str_contains($text, AssistantMessages::OUT_OF_SCOPE_MARKER)
            ? AssistantAnswer::OUT_OF_SCOPE
            : AssistantAnswer::ANSWERED;

        return new AssistantAnswer($text, $outcome, $this->provider->name(), $toolsUsed, $result, $iterations);
    }

    /**
     * Recorta los textos a 100 caracteres.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function clip(array $arguments): array
    {
        return array_map(fn (mixed $value) => is_string($value) ? mb_substr($value, 0, 100) : $value, $arguments);
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
