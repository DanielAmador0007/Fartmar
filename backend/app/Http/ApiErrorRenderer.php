<?php

namespace App\Http;

use App\Domain\Exceptions\BusinessRuleException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convierte excepciones en el formato de error uniforme de la API:
 *
 *   { "error": { "code": "STOCK_INSUFICIENTE", "message": "...", "details": {} } }
 *
 * Se registra en bootstrap/app.php. Devuelve null para lo que no reconoce
 * (errores 500 inesperados), que Laravel maneja por defecto sin exponer
 * detalles cuando APP_DEBUG=false.
 *
 * Nota: Laravel ya convirtió AuthorizationException en
 * AccessDeniedHttpException y ModelNotFoundException en NotFoundHttpException
 * antes de llegar aquí.
 */
final class ApiErrorRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof BusinessRuleException => self::error($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->details()),
            $e instanceof ValidationException => self::error(422, 'VALIDACION', 'Hay datos inválidos o incompletos en la solicitud.', ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => self::error(401, 'NO_AUTENTICADO', 'Debe iniciar sesión para continuar.'),
            $e instanceof AccessDeniedHttpException => self::error(403, 'NO_AUTORIZADO', 'Su rol no tiene permiso para realizar esta acción.'),
            $e instanceof NotFoundHttpException => self::error(404, 'NO_ENCONTRADO', 'El recurso solicitado no existe.'),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => self::error(429, 'DEMASIADAS_SOLICITUDES', 'Demasiados intentos. Espere un momento y vuelva a intentar.'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }
}
