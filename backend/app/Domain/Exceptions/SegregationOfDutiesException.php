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
