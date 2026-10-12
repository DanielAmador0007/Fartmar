<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Correlation-Id: identifica una petición de punta a punta (logs y filas
 * de BD: dispensaciones, kardex, audit_logs, patient_access_logs).
 *
 * - El correlation_id lo genera SIEMPRE el servidor (UUID): un cliente no
 *   puede hacer que dos operaciones distintas compartan id en la bitácora ni
 *   "reutilizar" el de otra persona (S-74).
 * - Si el cliente envía su propio X-Correlation-Id con formato válido
 *   ([A-Za-z0-9-] de 1 a 64, incluye UUID), se conserva aparte como
 *   client_correlation_id (en los logs) y se devuelve en
 *   X-Client-Correlation-Id, para que el frontend relacione su petición con
 *   la del servidor. Uno inválido se ignora (evita inyectar texto en logs).
 * - Ambos van a Laravel Context, que los agrega a cada línea de log, junto
 *   con la IP.
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public const CLIENT_HEADER = 'X-Client-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) Str::uuid();
        Context::add('correlation_id', $correlationId);
        Context::add('ip', $request->ip());

        $incoming = $request->headers->get(self::HEADER);
        $clientId = is_string($incoming) && preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $incoming) === 1 ? $incoming : null;
        if ($clientId !== null) {
            Context::add('client_correlation_id', $clientId);
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);
        if ($clientId !== null) {
            $response->headers->set(self::CLIENT_HEADER, $clientId);
        }

        return $response;
    }
}
