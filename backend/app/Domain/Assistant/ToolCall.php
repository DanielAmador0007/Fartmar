<?php

namespace App\Domain\Assistant;

/**
 * Pedido del modelo para ejecutar una herramienta: nombre y argumentos tal
 * como los mandó (sin validar: el servidor los valida en ToolRegistry).
 */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}
}
