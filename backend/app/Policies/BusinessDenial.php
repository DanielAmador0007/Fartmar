<?php

namespace App\Policies;

use App\Domain\Exceptions\BusinessRuleException;
use Illuminate\Auth\Access\Response;

/**
 * Respuesta de Policy que niega con una regla de negocio (p. ej. RN-08:
 * quien solicitó no aprueba) en vez del genérico NO_AUTORIZADO.
 *
 * - Gate::allows() / $user->can() devuelven false sin lanzar nada (sirve
 *   para calcular allowed_actions).
 * - Gate::authorize() lanza AuthorizationException con esta respuesta;
 *   ApiErrorRenderer la reconoce y responde con el código, mensaje y
 *   detalles de la excepción de dominio, igual que si la lanzara el servicio:
 *   { "error": { "code": "SEGREGACION_FUNCIONES", "message": "...", "details": {...} } }
 */
final class BusinessDenial extends Response
{
    public function __construct(public readonly BusinessRuleException $exception)
    {
        parent::__construct(false, $exception->getMessage(), $exception->errorCode());
    }

    public static function because(BusinessRuleException $exception): self
    {
        return new self($exception);
    }
}
