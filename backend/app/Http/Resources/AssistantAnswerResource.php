<?php

namespace App\Http\Resources;

use App\Domain\Assistant\AssistantAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta del asistente IA.
 *
 * - answer: texto para mostrar (texto plano, puede traer saltos de línea).
 * - outcome: answered | refused | out_of_scope.
 * - tools_used: herramientas que pidió el modelo, con sus argumentos tal
 *   como los mandó y si se ejecutaron (ok).
 * - result: datos de la última herramienta de consulta ejecutada
 *   ({tool, filters, count, rows, ...}) para pintar una tabla, o null. Las
 *   observaciones de traslados vienen como {untrusted: true, text}: mostrarlas
 *   como texto, nunca como HTML.
 *
 * @property AssistantAnswer $resource
 */
class AssistantAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'answer' => $this->resource->answer,
            'outcome' => $this->resource->outcome,
            'provider' => $this->resource->provider,
            'tools_used' => array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'arguments' => (object) $tool['arguments'],
                'ok' => $tool['ok'],
            ], $this->resource->toolsUsed),
            'result' => $this->resource->result,
        ];
    }
}
