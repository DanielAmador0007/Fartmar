<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Correlation-Id: identifica una petición de punta a punta (frontend, API,
 * logs y filas de BD).
 *
 * - Si el cliente envía uno válido (UUID o [A-Za-z0-9-] de 1 a 64), se usa;
 *   si no, se genera un UUID. El formato estricto evita inyectar saltos de
 *   línea o texto arbitrario en los logs.
 * - Se guarda en Laravel Context junto con la IP: Context se agrega solo a
 *   cada línea de log, y AuditLogger, KardexService, DispenseService y
 *   PatientAccessLogger lo leen para llenar correlation_id e ip.
 * - Se devuelve en la respuesta (también en errores 4xx/5xx).
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $correlationId = is_string($incoming) && preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('correlation_id', $correlationId);
        Context::add('ip', $request->ip());

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
