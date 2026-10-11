<?php

/*
| Máquina de estados del traslado (RN-07): se prueban TODAS las parejas
| (desde, hacia) de los 7 estados = 49 casos. Clase pura, sin BD.
*/

use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Transfers\TransferStateMachine;
use App\Enums\TransferStatus;

/**
 * Las únicas transiciones válidas, escritas a mano a partir de RN-07 (no se
 * copian de la clase, para que la prueba sí detecte un cambio en el mapa).
 *
 * @return list<string> "DESDE->HACIA"
 */
function allowedTransferTransitions(): array
{
    return [
        'BORRADOR->SOLICITADO',
        'BORRADOR->ANULADO',
        'SOLICITADO->APROBADO',
        'SOLICITADO->ANULADO',
        'APROBADO->EN_TRANSITO',
        'APROBADO->ANULADO',
        'EN_TRANSITO->RECIBIDO',
        'EN_TRANSITO->RECIBIDO_PARCIAL',
    ];
}

/**
 * @return array<string, array{0: TransferStatus, 1: TransferStatus}>
 */
function everyTransferPair(): array
{
    $pairs = [];
    foreach (TransferStatus::cases() as $from) {
        foreach (TransferStatus::cases() as $to) {
            $pairs["{$from->value}->{$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
}

it('acepta o rechaza cada pareja (desde, hacia) según RN-07', function (TransferStatus $from, TransferStatus $to) {
    $machine = new TransferStateMachine;
    $expected = in_array("{$from->value}->{$to->value}", allowedTransferTransitions(), true);

    expect($machine->canTransition($from, $to))->toBe($expected);

    if ($expected) {
        $machine->assertCanTransition($from, $to, 7);

        return;
    }

    try {
        $machine->assertCanTransition($from, $to, 7);
        test()->fail("Se aceptó {$from->value} -> {$to->value}.");
    } catch (InvalidTransitionException $e) {
        expect($e->errorCode())->toBe('TRANSICION_INVALIDA')
            ->and($e->httpStatus())->toBe(409)
            ->and($e->details())->toBe(['entity' => 'transfer', 'id' => 7, 'from' => $from->value, 'to' => $to->value]);
    }
})->with(everyTransferPair());

it('hay exactamente 8 transiciones válidas', function () {
    $machine = new TransferStateMachine;
    $valid = array_filter(everyTransferPair(), fn (array $pair) => $machine->canTransition(...$pair));

    expect(array_keys($valid))->toEqualCanonicalizing(allowedTransferTransitions());
});

it('RN-07: ANULADO solo antes de EN_TRANSITO', function () {
    $machine = new TransferStateMachine;

    expect($machine->canTransition(TransferStatus::Aprobado, TransferStatus::Anulado))->toBeTrue()
        ->and($machine->canTransition(TransferStatus::EnTransito, TransferStatus::Anulado))->toBeFalse()
        ->and($machine->canTransition(TransferStatus::Recibido, TransferStatus::Anulado))->toBeFalse()
        ->and($machine->canTransition(TransferStatus::RecibidoParcial, TransferStatus::Anulado))->toBeFalse();
});

it('los estados finales no tienen salida', function (TransferStatus $final) {
    expect((new TransferStateMachine)->allowedFrom($final))->toBe([]);
})->with([TransferStatus::Recibido, TransferStatus::RecibidoParcial, TransferStatus::Anulado]);
