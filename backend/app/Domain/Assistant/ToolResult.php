<?php

namespace App\Domain\Assistant;

/**
 * Resultado de ejecutar (o de negarse a ejecutar) una herramienta. Un error
 * aquí es controlado: se le devuelve al modelo como dato, no se lanza.
 *
 * Dos vistas de los mismos datos:
 * - $data: lo que recibe la UI (result de la API), p. ej. el texto de las
 *   observaciones de un traslado marcado {untrusted: true, text}.
 * - $modelData: lo que se le envía al MODELO (toPayload). Puede tener menos
 *   (AssistantTool::forModel): el texto libre escrito por usuarios nunca
 *   sale hacia el proveedor, solo una marca de que existe.
 *
 * $arguments son los argumentos YA validados (y normalizados) con que se
 * ejecutó; si no se ejecutó, quedan vacíos.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $modelData
     * @param  array<string, mixed>  $arguments
     */
    private function __construct(
        public bool $ok,
        public array $data = [],
        public array $modelData = [],
        public array $arguments = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  para la UI
     * @param  array<string, mixed>|null  $modelData  para el modelo (null = igual que $data)
     * @param  array<string, mixed>  $arguments  argumentos validados
     */
    public static function success(array $data, ?array $modelData = null, array $arguments = []): self
    {
        return new self(true, $data, $modelData ?? $data, $arguments);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, [], [], [], $code, $message);
    }

    /**
     * Lo que se serializa dentro de <tool_result> para el modelo.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->ok
            ? ['ok' => true, 'data' => $this->modelData]
            : ['ok' => false, 'error' => ['code' => $this->errorCode, 'message' => $this->errorMessage]];
    }
}
