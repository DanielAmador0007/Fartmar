<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * "tap" de config/logging.php: agrega RedactSensitiveData a un canal.
 *
 * Se usa tap (y no "processors") porque funciona con cualquier driver
 * (monolog, single, daily...). Laravel agrega después el procesador de
 * Context (correlation_id, ip); Monolog ejecuta primero el último agregado,
 * así que la redacción corre al final y también revisa ese contexto.
 */
final class UseRedaction
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveData);
        }
    }
}
