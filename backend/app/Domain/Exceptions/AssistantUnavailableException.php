<?php

namespace App\Domain\Exceptions;

/**
 * El proveedor del modelo no respondió, respondió con error o no está
 * configurado. El mensaje no incluye el error externo (podría traer la URL,
 * la llave o parte de la conversación); el detalle técnico va al log sin
 * la pregunta ni la respuesta.
 */
final class AssistantUnavailableException extends BusinessRuleException
{
    public static function forProvider(string $provider): self
    {
        return new self(
            'El asistente no está disponible en este momento. Intente más tarde; el resto del sistema sigue funcionando.',
            ['provider' => $provider],
        );
    }

    public function errorCode(): string
    {
        return 'ASISTENTE_NO_DISPONIBLE';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
