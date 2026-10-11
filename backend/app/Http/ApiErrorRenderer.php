<?php

namespace App\Http;

use App\Database\PostgresError;
use App\Domain\Exceptions\BusinessRuleException;
use App\Policies\BusinessDenial;
use Illuminate\Auth\Access\AuthorizationException;
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

        // Policy que negó por una regla de negocio (BusinessDenial): mismo
        // JSON que si el servicio hubiera lanzado la excepción de dominio.
        $denial = self::businessDenial($e);
        if ($denial !== null) {
            $e = $denial;
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
     * Laravel envuelve la AuthorizationException en AccessDeniedHttpException
     * (la original queda como "previous").
     */
    private static function businessDenial(Throwable $e): ?BusinessRuleException
    {
        $authorization = $e instanceof AuthorizationException ? $e : $e->getPrevious();
        if (! $authorization instanceof AuthorizationException) {
            return null;
        }

        $response = $authorization->response();

        return $response instanceof BusinessDenial ? $response->exception : null;
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
            PostgresError::isViolationOf($e, PostgresError::CHECK_VIOLATION, 'transfers_approver_differs') => self::error(
                403,
                'SEGREGACION_FUNCIONES',
                'Quien solicitó el traslado no puede aprobarlo; debe hacerlo otro regente de farmacia.',
            ),
            PostgresError::isViolationOf($e, PostgresError::CHECK_VIOLATION, 'kardex_movements_adjustment_reason_required') => self::error(
                422,
                'AJUSTE_INVALIDO',
                'Todo ajuste de inventario necesita un motivo.',
            ),
            // Triggers de traslados (Fase 3). El servicio valida antes con su error
            // específico; si la BD los dispara es porque el traslado cambió por
            // otro camino. Los demás triggers (saldo de kardex, faltante) indican
            // un bug y siguen como 500.
            in_array(PostgresError::triggerGuard($e), [
                'transfers_check_transition',
                'transfer_items_only_in_draft',
                'transfer_item_lots_freeze',
                'transfer_discrepancies_freeze_resolved',
            ], true) => self::error(
                409,
                'TRANSICION_INVALIDA',
                'El traslado ya no está en un estado que permita esta acción. Actualice la pantalla e intente de nuevo.',
            ),
            PostgresError::triggerGuard($e) === 'transfer_discrepancies_check_resolver' => self::error(
                403,
                'SEGREGACION_FUNCIONES',
                'Usted recibió este traslado; la discrepancia debe resolverla otro regente de farmacia.',
            ),
            PostgresError::triggerGuard($e) === 'transfer_item_lots_check_lot' => self::error(
                422,
                'LOTE_VENCIDO',
                'Un lote vencido no se puede trasladar (RN-01).',
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
