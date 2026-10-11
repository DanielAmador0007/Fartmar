<?php

namespace App\Domain\Transfers;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Qué acciones puede ejecutar AHORA un usuario sobre un traslado: la acción
 * debe ser una transición válida desde el estado actual (TransferStateMachine)
 * Y estar permitida por TransferPolicy para ese usuario.
 *
 * Solo informa (para que el frontend decida qué botones mostrar); no
 * reemplaza las validaciones: cada endpoint vuelve a autorizar y el servicio
 * vuelve a validar con la fila bloqueada. Sin consultas a la BD: la Policy
 * solo mira el usuario y columnas del traslado (sin N+1 en listados).
 */
final class TransferActions
{
    /**
     * Acción (nombre del método de la Policy y del endpoint) => estado al que lleva.
     * "receive" puede terminar en RECIBIDO o RECIBIDO_PARCIAL; ambos salen de EN_TRANSITO.
     */
    private const ACTIONS = [
        'submit' => TransferStatus::Solicitado,
        'approve' => TransferStatus::Aprobado,
        'dispatch' => TransferStatus::EnTransito,
        'receive' => TransferStatus::Recibido,
        'cancel' => TransferStatus::Anulado,
    ];

    public function __construct(private readonly TransferStateMachine $states) {}

    /**
     * @return list<string>
     */
    public function allowedFor(User $user, Transfer $transfer): array
    {
        $gate = Gate::forUser($user);
        $allowed = [];

        foreach (self::ACTIONS as $action => $target) {
            if ($this->states->canTransition($transfer->status, $target) && $gate->allows($action, $transfer)) {
                $allowed[] = $action;
            }
        }

        return $allowed;
    }
}
