<?php

namespace Tests\Support;

use App\Domain\Assistant\LlmProvider;
use App\Domain\Assistant\LlmResponse;
use RuntimeException;

/**
 * Proveedor de prueba: devuelve respuestas guionadas en orden (o delega en
 * otro proveedor, p. ej. el mock) y guarda los mensajes que recibió en cada
 * llamada, para verificar qué se le habría enviado a un modelo real.
 */
final class ScriptedLlmProvider implements LlmProvider
{
    /** @var list<list<array<string, mixed>>> mensajes recibidos por llamada */
    public array $received = [];

    /**
     * @param  list<LlmResponse>  $script
     */
    public function __construct(
        private array $script = [],
        private readonly ?LlmProvider $delegate = null,
        private readonly bool $repeatLast = false,
    ) {}

    public static function wrapping(LlmProvider $provider): self
    {
        return new self([], $provider);
    }

    public function name(): string
    {
        return $this->delegate?->name() ?? 'scripted';
    }

    public function chat(array $messages, array $tools): LlmResponse
    {
        $this->received[] = $messages;

        if ($this->delegate !== null) {
            return $this->delegate->chat($messages, $tools);
        }

        if ($this->repeatLast && count($this->script) === 1) {
            return $this->script[0];
        }

        return array_shift($this->script) ?? throw new RuntimeException('El guion del proveedor se agotó.');
    }

    /** Todo lo que se envió al modelo, como un solo texto. */
    public function sentText(): string
    {
        return json_encode($this->received, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
