<?php

namespace App\Enums;

enum DispensationStatus: string
{
    case PendienteAutorizacion = 'PENDIENTE_AUTORIZACION';
    case Completada = 'COMPLETADA';
    case Rechazada = 'RECHAZADA';
}
