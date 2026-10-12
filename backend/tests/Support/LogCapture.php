<?php

namespace Tests\Support;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use RuntimeException;

/**
 * Captura en memoria lo que se escribiría en el log REAL de la aplicación:
 * usa el canal "json" de config/logging.php (mismos procesadores, incluida la
 * redacción, y mismo formateador JSON) y solo cambia el destino (stderr) por
 * un TestHandler. Así la prueba verifica la configuración que corre en el
 * contenedor, no una copia.
 */
final class LogCapture
{
    private function __construct(private readonly TestHandler $handler) {}

    public static function start(): self
    {
        config(['logging.default' => 'json']);
        Log::forgetChannel('json');

        $logger = Log::channel('json');
        $monolog = $logger instanceof Logger ? $logger->getLogger() : null;
        if (! $monolog instanceof Monolog) {
            throw new RuntimeException('El canal json no es un logger de Monolog.');
        }

        $original = $monolog->getHandlers()[0] ?? null;
        $handler = new TestHandler;
        if ($original instanceof FormattableHandlerInterface) {
            $handler->setFormatter($original->getFormatter());
        }
        $monolog->setHandlers([$handler]);

        return new self($handler);
    }

    /** Todo lo capturado, tal como saldría por stderr (líneas JSON). */
    public function output(): string
    {
        return implode('', array_map(fn (LogRecord $r): string => (string) $r->formatted, $this->handler->getRecords()));
    }

    /** @return list<LogRecord> */
    public function records(): array
    {
        return array_values($this->handler->getRecords());
    }

    public function formatter(): FormatterInterface
    {
        return $this->handler->getFormatter();
    }
}
