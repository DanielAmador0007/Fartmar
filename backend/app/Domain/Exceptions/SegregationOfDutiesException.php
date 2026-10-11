<?php

namespace App\Domain\Exceptions;

/**
 * RN-05 / RN-08: quien crea o solicita no puede autorizar o aprobar lo suyo,
 * y quien autoriza debe tener el rol exigido.
 */
final class SegregationOfDutiesException extends BusinessRuleException
{
    public static function sameUser(string $action): self
    {
        return new self(
            "Usted creó este registro; {$action} debe hacerlo otro regente de farmacia.",
            ['reason' => 'MISMO_USUARIO'],
        );
    }

    /**
     * El borrador de un traslado lo solicita quien lo creó (S-49): así
     * requested_by identifica a una sola persona y RN-08 no se esquiva
     * pidiéndole a otro que "envíe" el borrador.
     */
    public static function notRequester(string $action): self
    {
        return new self(
            "Solo quien creó el traslado puede {$action}.",
            ['reason' => 'NO_ES_SOLICITANTE'],
        );
    }

    /**
     * M5: quien recibió el traslado (y declaró el faltante) no resuelve la
     * discrepancia que generó su propia recepción.
     */
    public static function receiverCannotResolve(): self
    {
        return new self(
            'Usted recibió este traslado; la discrepancia debe resolverla otro regente de farmacia.',
            ['reason' => 'ES_RECEPTOR'],
        );
    }

    public static function roleRequired(string $action, string $role): self
    {
        return new self(
            "Para {$action} se requiere el rol {$role}.",
            ['reason' => 'ROL_REQUERIDO', 'role' => $role],
        );
    }

    public function errorCode(): string
    {
        return 'SEGREGACION_FUNCIONES';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
