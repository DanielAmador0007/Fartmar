<?php

namespace App\Http;

use App\Database\PostgresError;
use App\Domain\Exceptions\BusinessRuleException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
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

        // La BD frenó algo que el código ya debía impedir (última línea de
        // defensa) o la transacción perdió una carrera: errores conocidos que
        // se responden igual con o sin APP_DEBUG.
        if ($e instanceof QueryException) {
            $known = self::databaseError($e);
            if ($known !== null) {
                return $known;
            }
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
     * Errores de PostgreSQL con significado de negocio. Se identifican por
     * SQLSTATE + nombre del constraint (ver PostgresError), nunca por el
     * texto completo de la excepción. No se devuelven detalles: el mensaje de
     * la BD trae valores de la fila y SQL.
     */
    private static function databaseError(QueryException $e): ?JsonResponse
    {
        return match (true) {
            PostgresError::isViolationOf($e, PostgresError::CHECK_VIOLATION, 'stocks_quantity_non_negative') => self::error(
                409,
                'STOCK_INSUFICIENTE',
                'No hay existencias suficientes: otra operación tomó las unidades al mismo tiempo. Actualice el inventario e intente de nuevo.',
            ),
            PostgresError::isViolationOf($e, PostgresError::CHECK_VIOLATION, 'prescription_items_dispensed_le_prescribed') => self::error(
                422,
                'PRESCRIPCION_EXCEDIDA',
                'La cantidad supera lo que queda por entregar de la prescripción. Actualice la prescripción e intente de nuevo.',
            ),
            // DB::transaction(..., 3) ya reintentó y se agotaron los intentos.
            PostgresError::isConcurrencyFailure($e) => self::error(
                409,
                'CONFLICTO_CONCURRENCIA',
                'Otra operación estaba modificando los mismos datos. Espere un momento y reintente; no se aplicó ningún cambio.',
            ),
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
