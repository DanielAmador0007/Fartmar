<?php

namespace App\Enums;

/**
 * Cómo se resuelve una discrepancia de traslado (S-50). Coincide con el
 * CHECK transfer_discrepancies_resolution_action_check.
 *
 * - ReintegrarOrigen: las unidades nunca salieron físicamente (error de
 *   despacho) y vuelven a la bodega origen con un movimiento AJUSTE (+).
 * - Perdida: se perdieron o dañaron en el camino; solo se registra (el stock
 *   ya salió del origen con SALIDA_TRASLADO, no hay que mover nada).
 */
enum DiscrepancyResolution: string
{
    case ReintegrarOrigen = 'REINTEGRAR_ORIGEN';
    case Perdida = 'PERDIDA';
}
