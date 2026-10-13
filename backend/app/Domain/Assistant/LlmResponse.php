<?php

namespace App\Domain\Assistant;

/**
 * Respuesta del modelo: texto final o pedidos de herramientas (o ambos; si
 * trae herramientas, el texto se ignora y el bucle continúa).
 */
final readonly class LlmResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public ?string $text,
        public array $toolCalls = [],
    ) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    public static function toolCalls(ToolCall ...$calls): self
    {
        return new self(null, array_values($calls));
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
