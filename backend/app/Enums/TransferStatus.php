<?php

namespace App\Enums;

/**
 * Estados del traslado (RN-07). Las transiciones válidas se implementan en
 * App\Domain\Transfers (Fase 2); la BD solo garantiza estados válidos y
 * coherencia estado/actores.
 */
enum TransferStatus: string
{
    case Borrador = 'BORRADOR';
    case Solicitado = 'SOLICITADO';
    case Aprobado = 'APROBADO';
    case EnTransito = 'EN_TRANSITO';
    case Recibido = 'RECIBIDO';
    case RecibidoParcial = 'RECIBIDO_PARCIAL';
    case Anulado = 'ANULADO';
}
