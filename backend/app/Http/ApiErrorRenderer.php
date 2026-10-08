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
 * Se registra en bootstrap/app.php. Los errores 500 inesperados salen como
 * ERROR_INTERNO sin detalles cuando APP_DEBUG=false (producción); con
 * APP_DEBUG=true se deja el detalle de Laravel para depurar. La excepción
 * igual se registra en el log (el render no impide el report).
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
            // Se conservan los headers del limitador (Retry-After, X-RateLimit-*).
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => self::error(429, 'DEMASIADAS_SOLICITUDES', 'Demasiados intentos. Espere un momento y vuelva a intentar.')
                ->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface => null,
            // Error inesperado (p. ej. QueryException, cuyo mensaje trae SQL,
            // valores y host de la BD). Sin APP_DEBUG solo sale un mensaje
            // genérico; con APP_DEBUG (desarrollo) Laravel muestra el detalle.
            config('app.debug') !== true => self::error(500, 'ERROR_INTERNO', 'Ocurrió un error inesperado. Intente de nuevo o contacte a soporte.'),
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
