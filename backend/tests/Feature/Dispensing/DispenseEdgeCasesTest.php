<?php

/*
| Casos borde de DispenseService (QA): el "hoy" en la zona de Bogotá,
| cambios entre crear y autorizar un controlado, atomicidad con varias
| líneas y la idempotencia de solicitudes fallidas o pendientes.
*/

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Domain\Dispensing\DispenseService;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\PrescriptionExceededException;
use App\Domain\Exceptions\PrescriptionNotValidException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Domain\Inventory\StockService;
use App\Enums\DispensationStatus;
use App\Enums\KardexType;
use App\Enums\PrescriptionStatus;
use App\Models\Dispensation;
use App\Models\DispensationItemLot;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DispenseService::class);
});

function edgeKey(): string
{
    return (string) Str::uuid();
}

/** Instante UTC (la BD y la app guardan UTC; "hoy" se calcula en Bogotá, UTC-5). */
function utc(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'UTC');
}

function outflowCount(): int
{
    return KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count();
}

// --- RN-01: "hoy" de vencimiento en America/Bogota (S-13, S-14) ---

it('RN-01: a las 22:00 de Bogotá (03:00 UTC del día siguiente) un lote que vence hoy aún sale y el de ayer no', function () {
    // 2026-10-09 03:00 UTC = 2026-10-08 22:00 en Bogotá: "hoy" es el 8.
    $this->travelTo(utc('2026-10-09 03:00:00'));
    $s = DispensingScenario::make([[-1, 5], [0, 2], [30, 5]], prescribed: 10);
    [$yesterday, $today, $later] = $s->lots;
    expect($today->expires_at->toDateString())->toBe('2026-10-08');

    $this->service->create($s->auxiliar, $s->data(3), edgeKey());

    expect($s->stockOf($today))->toBe(0)
        ->and($s->stockOf($later))->toBe(4)
        ->and($s->stockOf($yesterday))->toBe(5);
});

it('RN-01: a la medianoche de Bogotá (05:00 UTC) el lote que vencía "hoy" ya no sale', function () {
    $this->travelTo(utc('2026-10-09 04:59:59'));
    $s = DispensingScenario::make([[0, 2], [30, 5]], prescribed: 10);
    [$expiring, $later] = $s->lots;

    $this->travelTo(utc('2026-10-09 05:00:00'));
    $this->service->create($s->auxiliar, $s->data(1), edgeKey());

    expect($s->stockOf($expiring))->toBe(2)
        ->and($s->stockOf($later))->toBe(4);
});

it('RN-04: la prescripción es vigente hasta las 23:59:59 de Bogotá de su valid_until', function () {
    $this->travelTo(utc('2026-10-09 04:59:59'));
    $s = DispensingScenario::make([[30, 10]], prescribed: 10);
    $s->prescription->update(['issued_at' => '2026-10-01', 'valid_until' => '2026-10-08']);

    expect($this->service->create($s->auxiliar, $s->data(1), edgeKey())->dispensation->status)
        ->toBe(DispensationStatus::Completada);

    $this->travelTo(utc('2026-10-09 05:00:00'));
    expect(fn () => $this->service->create($s->auxiliar, $s->data(1), edgeKey()))
        ->toThrow(PrescriptionNotValidException::class, 'venció');
});

// --- RN-04: parciales hasta el límite exacto ---

it('RN-04: parciales 3 + 7 llegan exacto al tope; una unidad más ya no se entrega', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 10);
    // Segunda línea pendiente: la prescripción sigue ACTIVA tras completar la primera.
    $other = Product::factory()->create();
    PrescriptionItem::factory()->create(['prescription_id' => $s->prescription->id, 'product_id' => $other->id]);

    $this->service->create($s->auxiliar, $s->data(3), edgeKey());
    $this->service->create($s->auxiliar, $s->data(7), edgeKey());

    expect($s->item->fresh()?->quantity_dispensed)->toBe(10)
        ->and($s->prescription->fresh()?->status)->toBe(PrescriptionStatus::Activa);

    try {
        $this->service->create($s->auxiliar, $s->data(1), edgeKey());
        $this->fail('Debía lanzar PrescriptionExceededException');
    } catch (PrescriptionExceededException $e) {
        expect($e->details())->toMatchArray(['requested' => 1, 'remaining' => 0]);
    }

    expect($s->totalStock())->toBe(40)
        ->and(outflowCount())->toBe(2);
});

it('RN-04: una prescripción COMPLETADA no admite más dispensaciones', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 4);
    $this->service->create($s->auxiliar, $s->data(4), edgeKey());

    expect($s->prescription->fresh()?->status)->toBe(PrescriptionStatus::Completada);

    $this->service->create($s->auxiliar, $s->data(1), edgeKey());
})->throws(PrescriptionNotValidException::class);

// --- RN-03 / RN-09: atomicidad con varias líneas ---

it('RN-03: si una de dos líneas no tiene stock no sale nada y la misma Idempotency-Key sirve para reintentar', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    $noStock = Product::factory()->create();
    $line2 = PrescriptionItem::factory()->create(['prescription_id' => $s->prescription->id, 'product_id' => $noStock->id, 'quantity_prescribed' => 2]);
    $data = new DispenseData($s->patient->id, $s->prescription->id, $s->warehouse->id, [
        new DispenseItemData($s->item->id, 3),
        new DispenseItemData($line2->id, 2),
    ]);
    $key = edgeKey();

    expect(fn () => $this->service->create($s->auxiliar, $data, $key))->toThrow(InsufficientStockException::class);

    expect($s->totalStock())->toBe(10)
        ->and(Dispensation::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(0);

    // Llega el stock que faltaba: el reintento con la misma clave sí se procesa
    // (la clave del intento fallido se deshizo con su transacción).
    $lot = Lot::factory()->for($noStock)->create();
    Stock::factory()->create(['warehouse_id' => $s->warehouse->id, 'lot_id' => $lot->id, 'quantity' => 2]);

    $result = $this->service->create($s->auxiliar, $data, $key);

    expect($result->replayed)->toBeFalse()
        ->and($result->dispensation->status)->toBe(DispensationStatus::Completada)
        ->and($s->totalStock())->toBe(7)
        ->and($line2->fresh()?->quantity_dispensed)->toBe(2)
        ->and($s->prescription->fresh()?->status)->toBe(PrescriptionStatus::Activa);
});

// --- RN-05: controlados ---

it('RN-05 / S-30: si una sola línea es controlada, toda la dispensación espera autorización', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    $controlled = Product::factory()->controlled()->create();
    $line2 = PrescriptionItem::factory()->create(['prescription_id' => $s->prescription->id, 'product_id' => $controlled->id, 'quantity_prescribed' => 1]);
    $lot = Lot::factory()->for($controlled)->create();
    Stock::factory()->create(['warehouse_id' => $s->warehouse->id, 'lot_id' => $lot->id, 'quantity' => 3]);

    $data = new DispenseData($s->patient->id, $s->prescription->id, $s->warehouse->id, [
        new DispenseItemData($s->item->id, 2),
        new DispenseItemData($line2->id, 1),
    ]);
    $pending = $this->service->create($s->auxiliar, $data, edgeKey())->dispensation;

    expect($pending->status)->toBe(DispensationStatus::PendienteAutorizacion)
        ->and($s->totalStock())->toBe(10)
        ->and(KardexMovement::query()->count())->toBe(0);

    $this->service->authorize($pending, $s->regente);

    expect($s->totalStock())->toBe(8)
        ->and((int) Stock::query()->where('lot_id', $lot->id)->value('quantity'))->toBe(2)
        ->and(outflowCount())->toBe(2);
});

it('RN-05: si el stock se consumió entre crear y autorizar, autorizar falla sin mover nada y luego puede reintentarse', function () {
    $s = DispensingScenario::make([[30, 3]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(3), edgeKey())->dispensation;

    // Al crear había 3; antes de autorizar una baja deja solo 1.
    DB::transaction(fn () => app(StockService::class)->decrease(
        $s->warehouse->id, $s->lots[0]->id, 2, KardexType::Ajuste, $s->regente2, null, 'Unidades averiadas',
    ));

    try {
        $this->service->authorize($pending, $s->regente);
        $this->fail('Debía lanzar InsufficientStockException');
    } catch (InsufficientStockException $e) {
        expect($e->details())->toMatchArray(['requested' => 3, 'available' => 1]);
    }

    expect($pending->fresh()?->status)->toBe(DispensationStatus::PendienteAutorizacion)
        ->and($pending->fresh()?->authorized_by)->toBeNull()
        ->and($s->totalStock())->toBe(1)
        ->and(outflowCount())->toBe(0)
        ->and(DispensationItemLot::query()->count())->toBe(0)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(0);

    // Repuesto el stock, la misma dispensación se autoriza.
    $s->addLot(60, 5);
    expect($this->service->authorize($pending, $s->regente)->status)->toBe(DispensationStatus::Completada)
        ->and($s->totalStock())->toBe(3);
});

it('RN-01 / RN-05: si el lote vence entre crear y autorizar, al autorizar ya no se usa', function () {
    $this->travelTo(utc('2026-10-08 15:00:00'));
    $s = DispensingScenario::make([[0, 5]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(2), edgeKey())->dispensation;

    $this->travelTo(utc('2026-10-09 15:00:00'));

    expect(fn () => $this->service->authorize($pending, $s->regente))->toThrow(InsufficientStockException::class);
    expect($s->totalStock())->toBe(5)
        ->and($pending->fresh()?->status)->toBe(DispensationStatus::PendienteAutorizacion);
});

it('RN-04 / RN-05: si la prescripción vence entre crear y autorizar, no se autoriza', function () {
    $this->travelTo(utc('2026-10-08 15:00:00'));
    $s = DispensingScenario::make([[30, 5]], prescribed: 5, controlled: true);
    $s->prescription->update(['issued_at' => '2026-10-01', 'valid_until' => '2026-10-08']);
    $pending = $this->service->create($s->auxiliar, $s->data(2), edgeKey())->dispensation;

    $this->travelTo(utc('2026-10-09 15:00:00'));

    expect(fn () => $this->service->authorize($pending, $s->regente))->toThrow(PrescriptionNotValidException::class);
    expect($s->totalStock())->toBe(5)
        ->and(outflowCount())->toBe(0);
});

it('RN-05: quien creó la dispensación tampoco puede rechazarla', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->regente, $s->data(2), edgeKey())->dispensation;

    expect(fn () => $this->service->reject($pending, $s->regente, 'Me arrepentí'))
        ->toThrow(SegregationOfDutiesException::class);
    expect($pending->fresh()?->status)->toBe(DispensationStatus::PendienteAutorizacion);
});

it('RN-05: un regente inactivo no puede autorizar (aunque llame al servicio directamente)', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $pending = $this->service->create($s->auxiliar, $s->data(2), edgeKey())->dispensation;
    $s->regente->update(['is_active' => false]);

    expect(fn () => $this->service->authorize($pending, $s->regente))->toThrow(SegregationOfDutiesException::class);
    expect($s->totalStock())->toBe(10);
});

// --- RN-09: idempotencia de una dispensación pendiente ---

it('RN-09: repetir la creación de un controlado pendiente no duplica la reserva de la prescripción', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $key = edgeKey();

    $first = $this->service->create($s->auxiliar, $s->data(3), $key);
    $again = $this->service->create($s->auxiliar, $s->data(3), $key);

    expect($again->replayed)->toBeTrue()
        ->and($again->dispensation->id)->toBe($first->dispensation->id)
        ->and(Dispensation::query()->count())->toBe(1);

    // Quedan 2 por reservar (5 - 3), no -1.
    expect($this->service->create($s->auxiliar, $s->data(2), edgeKey())->dispensation->status)
        ->toBe(DispensationStatus::PendienteAutorizacion);
    expect(fn () => $this->service->create($s->auxiliar, $s->data(1), edgeKey()))
        ->toThrow(PrescriptionExceededException::class);
});
