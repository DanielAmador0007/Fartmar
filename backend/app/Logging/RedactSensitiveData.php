<?php

namespace App\Logging;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use JsonSerializable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Procesador Monolog que quita datos personales y secretos de cada registro
 * de log ANTES de formatearlo (RN-10: ningún dato personal en logs).
 *
 * 1. Claves sensibles (en cualquier nivel de context/extra) => "[REDACTED]":
 *    contraseñas, tokens, cookies, documento, teléfono, correo, nombres,
 *    fecha de nacimiento, dirección y los "bindings" de SQL.
 * 2. Excepciones => resumen propio (clase, mensaje saneado, código, archivo,
 *    trace con solo archivo:línea y el SQL CON placeholders "?"), nunca el
 *    objeto completo: el mensaje de una QueryException trae el SQL con los
 *    valores (p. ej. el correo y el hash de la clave de un INSERT en users)
 *    y la PDOException anidada trae la línea DETAIL de PostgreSQL
 *    ("Key (email)=(...)", "Failing row contains (...)").
 * 3. Textos libres (mensaje del log y strings): solo la primera línea, se
 *    corta desde "(Connection: ..." (host, BD y SQL con valores) y se
 *    enmascaran correos; en el mensaje, también secuencias de 6+ dígitos
 *    (documentos y teléfonos).
 *
 * Es una red de seguridad: la regla sigue siendo no pasar datos personales
 * al log. La Idempotency-Key no se redacta: no es dato personal ni secreto
 * (S-28: solo sirve al mismo usuario que la creó).
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    /** Claves que se redactan si coinciden exactamente (en minúsculas). */
    private const SENSITIVE_KEYS = [
        'name', 'first_name', 'last_name', 'full_name', 'patient_name', 'username',
        'birth_date', 'dob', 'bindings',
        // Asistente IA: texto libre que puede traer datos personales (S-95).
        'question', 'answer', 'messages', 'prompt',
    ];

    /** Claves que se redactan si CONTIENEN alguno de estos fragmentos. */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key',
        'document', 'phone', 'email', 'birth', 'address',
    ];

    private const MAX_DEPTH = 8;

    private const MAX_TRACE_FRAMES = 15;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::sanitizeMessage($record->message),
            context: self::redactArray($record->context, 0),
            extra: self::redactArray($record->extra, 0),
        );
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = str_replace('-', '_', strtolower($key));

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mensaje principal del log: texto saneado + números largos ocultos.
     */
    public static function sanitizeMessage(string $message): string
    {
        return (string) preg_replace('/\d{6,}/', '[NUM]', self::sanitizeText($message));
    }

    /**
     * "SQLSTATE[23505]: ... \nDETAIL: Key (email)=(a@b.co) ... (Connection: pgsql, Host: db, SQL: insert ...)"
     * => "SQLSTATE[23505]: ..." (solo la primera línea, sin conexión ni SQL).
     */
    public static function sanitizeText(string $text): string
    {
        $connection = strpos($text, ' (Connection: ');
        if ($connection !== false) {
            $text = substr($text, 0, $connection);
        }

        $firstLine = strtok($text, "\r\n");
        $text = $firstLine === false ? '' : $firstLine;

        return (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[EMAIL]', $text);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function redactArray(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[...]'];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $clean[$key] = is_string($key) && self::isSensitiveKey($key)
                ? self::REDACTED
                : self::redactValue($value, $depth);
        }

        return $clean;
    }

    private static function redactValue(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            return self::summarizeException($value, $depth);
        }

        // Un modelo (p. ej. Patient) se serializaría con todos sus atributos.
        if ($value instanceof Model) {
            return ['model' => $value::class, 'id' => $value->getKey()];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        return match (true) {
            is_array($value) => self::redactArray($value, $depth + 1),
            is_string($value) => self::sanitizeText($value),
            is_object($value) => $value::class,
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarizeException(Throwable $e, int $depth): array
    {
        $summary = [
            'class' => $e::class,
            'message' => self::sanitizeMessage($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
        ];

        // SQL con placeholders "?" (sin valores) y la conexión, útiles para depurar.
        if ($e instanceof QueryException) {
            $summary['sql'] = $e->getSql();
            $summary['connection'] = $e->getConnectionName();
        }

        $summary['trace'] = array_map(
            fn (array $frame): string => ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0),
            array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES),
        );

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < 3) {
            $summary['previous'] = self::summarizeException($previous, $depth + 1);
        }

        return $summary;
    }
}
