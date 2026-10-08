<?php

namespace App\Enums;

/**
 * Una prescripción es "vigente" si status = ACTIVA y valid_until >= hoy
 * (zona de negocio). COMPLETADA: todo dispensado. ANULADA: no dispensable.
 */
enum PrescriptionStatus: string
{
    case Activa = 'ACTIVA';
    case Completada = 'COMPLETADA';
    case Anulada = 'ANULADA';
}
