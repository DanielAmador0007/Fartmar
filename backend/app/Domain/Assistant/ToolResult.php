<?php

namespace App\Domain\Assistant;

/**
 * Resultado de ejecutar (o de negarse a ejecutar) una herramienta. Un error
 * aquí es controlado: se le devuelve al modelo como dato, no se lanza.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public bool $ok,
        public array $data = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data): self
    {
        return new self(true, $data);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, [], $code, $message);
    }

    /**
     * Lo que se serializa dentro de <tool_result> para el modelo.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->ok
            ? ['ok' => true, 'data' => $this->data]
            : ['ok' => false, 'error' => ['code' => $this->errorCode, 'message' => $this->errorMessage]];
    }
}
