<?php

namespace App\Enums;

/**
 * Tipos de movimiento de kardex (RN-06). Coinciden con el CHECK
 * kardex_movements_type_check.
 */
enum KardexType: string
{
    case Entrada = 'ENTRADA';
    case SalidaDispensacion = 'SALIDA_DISPENSACION';
    case SalidaTraslado = 'SALIDA_TRASLADO';
    case EntradaTraslado = 'ENTRADA_TRASLADO';
    case Ajuste = 'AJUSTE';
}
