<?php

namespace App\Console\Commands;

use App\Domain\Assistant\AssistantAnswer;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\LlmProviderFactory;
use App\Domain\Assistant\Text;
use App\Domain\Exceptions\AssistantUnavailableException;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Corre el conjunto de evaluación del asistente (database/assistant/eval.json)
 * contra la BD actual (la de desarrollo con datos semilla) y reporta una
 * tabla con cada caso y el porcentaje de aciertos. Termina con código 1 si
 * queda por debajo del umbral, para usarlo en CI.
 *
 *   php artisan assistant:eval                       (mock, umbral de config)
 *   php artisan assistant:eval --provider=ollama --threshold=70
 *
 * Un caso acierta si se cumple TODO lo que declara: comportamiento
 * (answer/refuse/out_of_scope), herramienta usada con los argumentos
 * esperados (parcial), textos que debe y no debe contener la respuesta, y
 * ningún dato personal de la lista global.
 */
class AssistantEvalCommand extends Command
{
    protected $signature = 'assistant:eval
        {--provider=mock : Proveedor a evaluar (mock, ollama, anthropic)}
        {--threshold= : Porcentaje mínimo de aciertos (por defecto ASSISTANT_EVAL_THRESHOLD)}
        {--file= : Ruta del conjunto de evaluación (por defecto database/assistant/eval.json)}';

    protected $description = 'Evalúa el asistente IA con el conjunto de preguntas y reporta los aciertos';

    public function handle(LlmProviderFactory $factory): int
    {
        $path = (string) ($this->option('file') ?: base_path('database/assistant/eval.json'));
        $suite = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($suite) || ! is_array($suite['cases'] ?? null) || $suite['cases'] === []) {
            $this->error("No se pudo leer el conjunto de evaluación: {$path}");

            return self::FAILURE;
        }

        $providerName = (string) $this->option('provider');
        try {
            $assistant = app()->make(AssistantService::class, ['provider' => $factory->make($providerName)]);
        } catch (AssistantUnavailableException) {
            $this->error("Proveedor desconocido o no configurado: {$providerName}");

            return self::FAILURE;
        }

        // Las líneas de log (assistant.query) ensucian la tabla; con -v se ven.
        if (! $this->output->isVerbose()) {
            Log::setDefaultDriver('null');
        }

        // Los casos asumen la semilla sin modificar: avisar si ya hubo
        // dispensaciones o ajustes (algún caso puede fallar con una respuesta
        // correcta para esos datos).
        if (KardexMovement::query()->whereNotIn('type', [KardexType::Entrada->value, KardexType::SalidaTraslado->value])->exists()) {
            $this->warn('Aviso: la BD tiene movimientos posteriores a la semilla (dispensaciones o ajustes). Para resultados comparables: make fresh.');
        }

        $threshold = (float) ($this->option('threshold') ?? config('assistant.eval_threshold', 90));
        $forbidden = array_map('strval', $suite['forbidden_in_all_answers'] ?? []);
        $rows = [];
        $passed = 0;

        foreach ($suite['cases'] as $i => $case) {
            $id = (string) ($case['id'] ?? 'C'.($i + 1));
            $email = (string) ($case['user'] ?? $suite['default_user'] ?? 'auxiliar@fartmar.test');
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("No existe el usuario {$email}. Ejecute primero: php artisan db:seed");

                return self::FAILURE;
            }

            try {
                $answer = $assistant->ask($user, (string) $case['question']);
                $failures = $this->check($case, $answer, $forbidden);
                $tools = implode(', ', array_column($answer->toolsUsed, 'name')) ?: '-';
            } catch (AssistantUnavailableException) {
                $failures = ['proveedor no disponible'];
                $tools = '-';
            }

            $ok = $failures === [];
            $passed += $ok ? 1 : 0;
            $rows[] = [
                $id,
                mb_strimwidth((string) $case['question'], 0, 60, '…'),
                $tools,
                $ok ? 'OK' : 'FALLA',
                $ok ? '' : mb_strimwidth(implode('; ', $failures), 0, 90, '…'),
            ];
        }

        $total = count($suite['cases']);
        $percent = round($passed * 100 / $total, 1);

        $this->table(['#', 'Pregunta', 'Herramientas', 'Resultado', 'Motivo'], $rows);
        $this->line(sprintf('Proveedor: %s', $assistant->providerName()));
        $this->line(sprintf('%d/%d aciertos (%s %%)', $passed, $total, number_format($percent, 1, ',', '')));

        if ($percent < $threshold) {
            $this->error(sprintf('Por debajo del umbral de %s %%.', number_format($threshold, 1, ',', '')));

            return self::FAILURE;
        }

        $this->info(sprintf('Umbral de %s %% superado.', number_format($threshold, 1, ',', '')));

        return self::SUCCESS;
    }

    /**
     * Lista de motivos por los que el caso falla ([] = acierto).
     *
     * @param  array<string, mixed>  $case
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    private function check(array $case, AssistantAnswer $answer, array $forbidden): array
    {
        $failures = [];
        $text = Text::normalize($answer->answer);

        $behavior = (string) ($case['expected_behavior'] ?? 'answer');
        $expectedOutcome = match ($behavior) {
            'refuse' => AssistantAnswer::REFUSED,
            'out_of_scope' => AssistantAnswer::OUT_OF_SCOPE,
            default => AssistantAnswer::ANSWERED,
        };
        if ($answer->outcome !== $expectedOutcome) {
            $failures[] = "esperaba {$behavior}, obtuvo {$answer->outcome}";
        }

        $tool = $case['expected_tool'] ?? null;
        if (is_string($tool)) {
            $expectedArgs = (array) ($case['expected_args'] ?? []);
            $matched = array_filter($answer->toolsUsed, fn (array $used) => $used['name'] === $tool
                && $this->argsMatch($expectedArgs, $used['arguments']));

            if ($matched === []) {
                $failures[] = "no usó {$tool} con ".json_encode($expectedArgs, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($case['expected_tool_ok'] ?? null)
                && ! in_array($case['expected_tool_ok'], array_column($matched, 'ok'), true)) {
                $failures[] = $case['expected_tool_ok'] ? "{$tool} no se ejecutó" : "{$tool} debía ser rechazada";
            }
        } elseif ($behavior !== 'answer' && $answer->toolsUsed !== []) {
            $failures[] = 'usó herramientas y no debía';
        }

        // Datos estructurados (independientes de la redacción del modelo).
        if (is_int($case['expected_result_count'] ?? null) && ($answer->result['count'] ?? null) !== $case['expected_result_count']) {
            $failures[] = "result.count distinto de {$case['expected_result_count']}";
        }

        $resultText = Text::normalize((string) json_encode($answer->result, JSON_UNESCAPED_UNICODE));
        foreach ((array) ($case['expected_result_contains'] ?? []) as $needle) {
            if (! str_contains($resultText, Text::normalize((string) $needle))) {
                $failures[] = "result sin \"{$needle}\"";
            }
        }

        // Texto de la respuesta: verificación secundaria.
        foreach ((array) ($case['expected_answer_contains'] ?? []) as $needle) {
            if (! str_contains($text, Text::normalize((string) $needle))) {
                $failures[] = "falta \"{$needle}\"";
            }
        }

        // Alternativas: basta con que aparezca una (otra redacción también vale).
        $anyOf = (array) ($case['expected_answer_contains_any'] ?? []);
        if ($anyOf !== [] && array_filter($anyOf, fn ($needle) => str_contains($text, Text::normalize((string) $needle))) === []) {
            $failures[] = 'no contiene ninguna de: '.implode(' | ', $anyOf);
        }

        foreach ([...(array) ($case['expected_answer_not_contains'] ?? []), ...$forbidden] as $needle) {
            if (str_contains($text, Text::normalize((string) $needle))) {
                $failures[] = "contiene \"{$needle}\"";
            }
        }

        return $failures;
    }

    /**
     * Comparación parcial: cada argumento esperado debe estar y coincidir
     * (texto sin mayúsculas ni tildes; números por valor).
     *
     * @param  array<array-key, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function argsMatch(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            $same = is_string($value)
                ? Text::normalize($value) === Text::normalize((string) $actual[$key])
                : $value == $actual[$key];

            if (! $same) {
                return false;
            }
        }

        return true;
    }
}
