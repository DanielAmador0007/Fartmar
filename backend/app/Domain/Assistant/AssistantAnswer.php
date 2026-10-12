<?php

namespace App\Domain\Assistant;

/**
 * Resultado de una consulta al asistente.
 *
 * outcome:
 * - answered:     respondió (con o sin filas) usando herramientas.
 * - refused:      el servidor se negó (pacientes, SQL, escritura, inyección)
 *                 o el filtro de salida bloqueó la respuesta del modelo.
 * - out_of_scope: la pregunta no es de inventario (respuesta estándar).
 */
final readonly class AssistantAnswer
{
    public const ANSWERED = 'answered';

    public const REFUSED = 'refused';

    public const OUT_OF_SCOPE = 'out_of_scope';

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, ok: bool}>  $toolsUsed
     * @param  array<string, mixed>|null  $result  datos de la última herramienta de consulta (para una tabla en la UI)
     */
    public function __construct(
        public string $answer,
        public string $outcome,
        public string $provider,
        public array $toolsUsed = [],
        public ?array $result = null,
        public int $iterations = 0,
    ) {}
}
