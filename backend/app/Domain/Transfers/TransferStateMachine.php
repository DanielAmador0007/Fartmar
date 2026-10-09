<?php

namespace App\Domain\Transfers;

use App\Domain\Exceptions\InvalidTransitionException;
use App\Enums\TransferStatus;

/**
 * Máquina de estados del traslado (RN-07). Clase pura: no toca la BD.
 *
 *   BORRADOR -> SOLICITADO -> APROBADO -> EN_TRANSITO -> RECIBIDO
 *                                                     -> RECIBIDO_PARCIAL
 *   BORRADOR | SOLICITADO | APROBADO -> ANULADO   (nunca después de despachar)
 *
 * Cualquier otra pareja (from, to) es inválida, incluida "quedarse en el
 * mismo estado" (p. ej. aprobar dos veces). RECIBIDO, RECIBIDO_PARCIAL y
 * ANULADO son finales.
 *
 * La BD solo valida la fila resultante (actores y fechas de cada estado), no
 * el paso; por eso TransferService llama assertCanTransition() con la fila
 * del traslado ya bloqueada (FOR UPDATE) antes de cambiar nada.
 */
final class TransferStateMachine
{
    /**
     * Mapa explícito: estado actual => estados a los que se puede pasar.
     *
     * @var array<string, list<TransferStatus>>
     */
    private const TRANSITIONS = [
        'BORRADOR' => [TransferStatus::Solicitado, TransferStatus::Anulado],
        'SOLICITADO' => [TransferStatus::Aprobado, TransferStatus::Anulado],
        'APROBADO' => [TransferStatus::EnTransito, TransferStatus::Anulado],
        'EN_TRANSITO' => [TransferStatus::Recibido, TransferStatus::RecibidoParcial],
        'RECIBIDO' => [],
        'RECIBIDO_PARCIAL' => [],
        'ANULADO' => [],
    ];

    public function canTransition(TransferStatus $from, TransferStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * @throws InvalidTransitionException 409 TRANSICION_INVALIDA
     */
    public function assertCanTransition(TransferStatus $from, TransferStatus $to, ?int $transferId = null): void
    {
        if (! $this->canTransition($from, $to)) {
            throw InvalidTransitionException::between('transfer', $transferId, $from->value, $to->value);
        }
    }

    /**
     * @return list<TransferStatus>
     */
    public function allowedFrom(TransferStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
