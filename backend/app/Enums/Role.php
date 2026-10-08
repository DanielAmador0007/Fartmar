<?php

namespace App\Enums;

/**
 * Roles del sistema. Los valores deben coincidir con el CHECK users_role_check.
 */
enum Role: string
{
    case AuxiliarFarmacia = 'auxiliar_farmacia';
    case RegenteFarmacia = 'regente_farmacia';
    case Medico = 'medico';
    case Auditor = 'auditor';
    case Admin = 'admin';
}
