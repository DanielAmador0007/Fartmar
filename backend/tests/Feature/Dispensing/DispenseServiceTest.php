<?php

/*
| DispenseService (RN-01..RN-06, RN-09) probado directamente, sin HTTP.
*/

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Domain\Dispensing\DispenseService;
use App\Domain\Dispensing\IdempotencyGuard;
use App\Domain\Exceptions\IdempotencyConflictException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\PrescriptionExceededException;
use App\Domain\Exceptions\PrescriptionNotValidException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Enums\DispensationStatus;
use App\Enums\PrescriptionStatus;
use App\Models\AuditLog;
use App\Models\Dispensation;
use App\Models\DispensationItemLot;
use App\Models\KardexMovement;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DispenseService::class);
});

function newKey(): string
{
    return (string) Str::uuid();
}

it('RN-02: completa la dispensación tomando lotes en orden FEFO y varios lotes si hace falta', function () {
    // Lotes: vence en 200 días (20 u.), en 10 días (3 u.), en 40 días (5 u.).
    $s = DispensingScenario::make([[200, 20], [10, 3], [40, 5]], prescribed: 10);
    [$late, $first, $second] = $s->lots;

    $result = $this->service->create($s->auxiliar, $s->data(10), newKey());

    expect($result->replayed)->toBeFalse()
        ->and($result->dispensation->status)->toBe(DispensationStatus::Completada)
        ->and($s->stockOf($first))->toBe(0)
        ->and($s->stockOf($second))->toBe(0)
        ->and($s->stockOf($late))->toBe(18);

    $lots = DispensationItemLot::query()->orderBy('id')->get(['lot_id', 'quantity'])->toArray();
    expect($lots)->toBe([
        ['lot_id' => $first->id, 'quantity' => 3],
        ['lot_id' => $second->id, 'quantity' => 5],
        ['lot_id' => $late->id, 'quantity' => 2],
    ]);
});

it('RN-06: cada lote descontado genera SALIDA_DISPENSACION con balance_after y referencia a la dispensación', function () {
    $s = DispensingScenario::make([[10, 3], [40, 5]], prescribed: 6);

    $dispensation = $this->service->create($s->auxiliar, $s->data(6), newKey())->dispensation;

    $movements = KardexMovement::query()->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->map(fn ($m) => [$m->lot_id, $m->type->value, $m->quantity, $m->direction, $m->balance_after])->all())
        ->toBe([
            [$s->lots[0]->id, 'SALIDA_DISPENSACION', 3, -1, 0],
            [$s->lots[1]->id, 'SALIDA_DISPENSACION', 3, -1, 2],
        ])
        ->and($movements->pluck('reference_type')->unique()->all())->toBe(['dispensation'])
        ->and($movements->pluck('reference_id')->unique()->all())->toBe([$dispensation->id])
        ->and($movements->pluck('user_id')->unique()->all())->toBe([$s->auxiliar->id]);
});

it('RN-04: acumula parciales en quantity_dispensed y marca la prescripción COMPLETADA al entregar todo', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 10);

    $this->service->create($s->auxiliar, $s->data(4), newKey());
    expect($s->item->fresh()?->quantity_dispensed)->toBe(4)
        ->and($s->prescription->fresh()?->status)->toBe(PrescriptionStatus::Activa);

    $this->service->create($s->auxiliar, $s->data(6), newKey());
    expect($s->item->fresh()?->quantity_dispensed)->toBe(10)
        ->and($s->prescription->fresh()?->status)->toBe(PrescriptionStatus::Completada);
});

it('RN-04: rechaza pedir más de lo que queda por entregar (parciales acumuladas) sin mover stock', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 10);
    $this->service->create($s->auxiliar, $s->data(7), newKey());

    try {
        $this->service->create($s->auxiliar, $s->data(4), newKey());
        $this->fail('Debía lanzar PrescriptionExceededException');
    } catch (PrescriptionExceededException $e) {
        expect($e->details())->toMatchArray(['requested' => 4, 'remaining' => 3, 'quantity_dispensed' => 7]);
    }

    expect($s->totalStock())->toBe(43)
        ->and(Dispensation::query()->count())->toBe(1);
});

it('RN-04: rechaza una prescripción vencida', function () {
    $s = DispensingScenario::make();
    $s->prescription->update(['issued_at' => now()->subDays(40), 'valid_until' => now('America/Bogota')->subDay()->toDateString()]);

    $this->service->create($s->auxiliar, $s->data(1), newKey());
})->throws(PrescriptionNotValidException::class, 'venció');

it('RN-04: rechaza una prescripción anulada', function () {
    $s = DispensingScenario::make();
    $s->prescription->update(['status' => PrescriptionStatus::Anulada]);

    $this->service->create($s->auxiliar, $s->data(1), newKey());
})->throws(PrescriptionNotValidException::class, 'anulada');

it('RN-04: rechaza si la prescripción no es del paciente indicado', function () {
    $s = DispensingScenario::make();
    $other = Prescription::factory()->create();

    $data = new DispenseData($other->patient_id, $s->prescription->id, $s->warehouse->id, [new DispenseItemData($s->item->id, 1)]);

    $this->service->create($s->auxiliar, $data, newKey());
})->throws(PrescriptionNotValidException::class, 'paciente');

it('RN-04: rechaza líneas que no pertenecen a la prescripción', function () {
    $s = DispensingScenario::make();
    $foreign = PrescriptionItem::factory()->create();

    $data = new DispenseData($s->patient->id, $s->prescription->id, $s->warehouse->id, [new DispenseItemData($foreign->id, 1)]);

    $this->service->create($s->auxiliar, $data, newKey());
})->throws(PrescriptionNotValidException::class);

it('RN-01/RN-03: si solo hay lotes vencidos no dispensa y no deja rastro', function () {
    $s = DispensingScenario::make([[-1, 100], [5, 2]], prescribed: 10);

    try {
        $this->service->create($s->auxiliar, $s->data(3), newKey());
        $this->fail('Debía lanzar InsufficientStockException');
    } catch (InsufficientStockException $e) {
        expect($e->details())->toBe([
            'warehouse_id' => $s->warehouse->id,
            'product_id' => $s->product->id,
            'requested' => 3,
            'available' => 2,
        ]);
    }

    expect($s->totalStock())->toBe(102)
        ->and(Dispensation::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(0);
});

it('RN-01: solo toma de la bodega indicada', function () {
    $s = DispensingScenario::make([[30, 1]], prescribed: 5);
    $s->addLot(5, 100, Warehouse::factory()->create());

    $this->service->create($s->auxiliar, $s->data(2), newKey());
})->throws(InsufficientStockException::class);

it('RN-09: repetir con la misma clave y el mismo contenido devuelve la original sin nueva salida', function () {
    $s = DispensingScenario::make([[30, 20]], prescribed: 10);
    $key = newKey();

    $first = $this->service->create($s->auxiliar, $s->data(3), $key);
    $second = $this->service->create($s->auxiliar, $s->data(3), $key);

    expect($second->replayed)->toBeTrue()
        ->and($second->dispensation->id)->toBe($first->dispensation->id)
        ->and($s->totalStock())->toBe(17)
        ->and(KardexMovement::query()->count())->toBe(1)
        ->and(Dispensation::query()->count())->toBe(1)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(3);
});

it('RN-09: misma clave con otro contenido es conflicto y no mueve stock', function () {
    $s = DispensingScenario::make([[30, 20]], prescribed: 10);
    $key = newKey();
    $this->service->create($s->auxiliar, $s->data(3), $key);

    expect(fn () => $this->service->create($s->auxiliar, $s->data(4), $key))
        ->toThrow(IdempotencyConflictException::class);

    expect($s->totalStock())->toBe(17);
});

it('RN-09: misma clave usada por otro usuario es conflicto', function () {
    $s = DispensingScenario::make([[30, 20]], prescribed: 10);
    $key = newKey();
    $this->service->create($s->auxiliar, $s->data(3), $key);

    $this->service->create(User::factory()->auxiliar()->create(), $s->data(3), $key);
})->throws(IdempotencyConflictException::class);

it('RN-09: carrera por la misma clave — el UNIQUE choca y se devuelve la dispensación ganadora', function () {
    $s = DispensingScenario::make([[30, 20]], prescribed: 10);
    $key = newKey();
    $winner = $this->service->create($s->auxiliar, $s->data(3), $key)->dispensation;

    // Simula el segundo reintento que leyó ANTES de que el primero
    // confirmara: su primera búsqueda no encuentra la clave.
    $real = new IdempotencyGuard;
    $calls = 0;
    $guard = Mockery::mock(IdempotencyGuard::class)->makePartial();
    $guard->shouldReceive('findReplay')->andReturnUsing(function (string $k, string $h) use ($real, &$calls) {
        return ++$calls === 1 ? null : $real->findReplay($k, $h);
    });
    app()->instance(IdempotencyGuard::class, $guard);

    $result = app(DispenseService::class)->create($s->auxiliar, $s->data(3), $key);

    expect($calls)->toBe(2)
        ->and($result->replayed)->toBeTrue()
        ->and($result->dispensation->id)->toBe($winner->id)
        ->and($s->totalStock())->toBe(17)
        ->and(KardexMovement::query()->count())->toBe(1)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(3);
});

it('RN-09: el orden de las líneas no cambia el hash', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 10);
    $other = Product::factory()->create();
    $line2 = PrescriptionItem::factory()->create(['prescription_id' => $s->prescription->id, 'product_id' => $other->id]);
    $guard = app(IdempotencyGuard::class);

    $a = new DispenseData($s->patient->id, $s->prescription->id, $s->warehouse->id, [new DispenseItemData($s->item->id, 1), new DispenseItemData($line2->id, 2)]);
    $b = new DispenseData($s->patient->id, $s->prescription->id, $s->warehouse->id, [new DispenseItemData($line2->id, 2), new DispenseItemData($s->item->id, 1)]);

    expect($guard->hash(1, $a))->toBe($guard->hash(1, $b))
        ->and($guard->hash(1, $a))->not->toBe($guard->hash(2, $a));
});

it('RN-05: un controlado queda PENDIENTE_AUTORIZACION sin mover stock ni lotes', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);

    $dispensation = $this->service->create($s->auxiliar, $s->data(2), newKey())->dispensation;

    expect($dispensation->status)->toBe(DispensationStatus::PendienteAutorizacion)
        ->and($dispensation->requires_authorization)->toBeTrue()
        ->and($dispensation->items()->count())->toBe(1)
        ->and(DispensationItemLot::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->totalStock())->toBe(10)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(0);
});

it('RN-05: un regente distinto autoriza y ahí se aplica FEFO y la salida de stock', function () {
    $s = DispensingScenario::make([[60, 10], [20, 1]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(3), newKey())->dispensation;

    $done = $this->service->authorize($pending, $s->regente);

    expect($done->status)->toBe(DispensationStatus::Completada)
        ->and($done->authorized_by)->toBe($s->regente->id)
        ->and($done->authorized_at)->not->toBeNull()
        ->and($s->stockOf($s->lots[1]))->toBe(0)
        ->and($s->stockOf($s->lots[0]))->toBe(8)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(3)
        ->and(KardexMovement::query()->pluck('user_id')->unique()->all())->toBe([$s->regente->id])
        ->and(AuditLog::query()->where('action', 'dispensation.authorized')->count())->toBe(1);
});

it('RN-05: quien creó la dispensación no puede autorizarla aunque sea regente', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->regente, $s->data(2), newKey())->dispensation;

    try {
        $this->service->authorize($pending, $s->regente);
        $this->fail('Debía lanzar SegregationOfDutiesException');
    } catch (SegregationOfDutiesException $e) {
        expect($e->errorCode())->toBe('SEGREGACION_FUNCIONES')->and($e->httpStatus())->toBe(403);
    }

    expect($pending->fresh()?->status)->toBe(DispensationStatus::PendienteAutorizacion)
        ->and($s->totalStock())->toBe(10);
});

it('RN-05: un auxiliar no puede autorizar', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->regente, $s->data(2), newKey())->dispensation;

    $this->service->authorize($pending, $s->auxiliar);
})->throws(SegregationOfDutiesException::class);

it('RN-05: no se puede autorizar dos veces ni autorizar una rechazada', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $a = $this->service->create($s->auxiliar, $s->data(2), newKey())->dispensation;
    $b = $this->service->create($s->auxiliar, $s->data(1), newKey())->dispensation;

    $this->service->authorize($a, $s->regente);
    expect(fn () => $this->service->authorize($a, $s->regente2))->toThrow(InvalidTransitionException::class);

    $this->service->reject($b, $s->regente, 'Fórmula ilegible');
    expect(fn () => $this->service->authorize($b, $s->regente2))->toThrow(InvalidTransitionException::class);

    expect($s->totalStock())->toBe(8);
});

it('RN-05: rechazar deja motivo, no mueve stock y libera la cantidad reservada', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(5), newKey())->dispensation;

    // Mientras está pendiente, las 5 unidades quedan reservadas.
    expect(fn () => $this->service->create($s->auxiliar, $s->data(1), newKey()))
        ->toThrow(PrescriptionExceededException::class);

    $rejected = $this->service->reject($pending, $s->regente, 'Paciente no presentó documento');

    expect($rejected->status)->toBe(DispensationStatus::Rechazada)
        ->and($rejected->rejected_by)->toBe($s->regente->id)
        ->and($rejected->rejection_reason)->toBe('Paciente no presentó documento')
        ->and($s->totalStock())->toBe(10);

    // Ya liberada, se puede volver a pedir.
    expect($this->service->create($s->auxiliar, $s->data(5), newKey())->dispensation->status)
        ->toBe(DispensationStatus::PendienteAutorizacion);
});

it('RN-05: al autorizar se revalida el stock disponible', function () {
    $s = DispensingScenario::make([[30, 2]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(3), newKey())->dispensation;

    expect(fn () => $this->service->authorize($pending, $s->regente))->toThrow(InsufficientStockException::class);

    expect($pending->fresh()?->status)->toBe(DispensationStatus::PendienteAutorizacion)
        ->and($s->totalStock())->toBe(2);
});

it('la vista previa FEFO muestra los lotes sin mover stock', function () {
    $s = DispensingScenario::make([[40, 5], [10, 2], [-3, 9]]);

    $preview = $this->service->preview($s->warehouse->id, $s->product, 4);

    expect(array_map(fn ($a) => [$a->lotId, $a->quantity], $preview->allocations))
        ->toBe([[$s->lots[1]->id, 2], [$s->lots[0]->id, 2]])
        ->and($s->totalStock())->toBe(16)
        ->and(KardexMovement::query()->count())->toBe(0);
});
