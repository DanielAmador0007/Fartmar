<?php

/*
| Concurrencia REAL de la dispensación (RN-02, RN-03, RN-06, RN-09).
|
| - Cada solicitud corre en un proceso PHP separado con su propia conexión a
|   PostgreSQL (ver Tests\Support\ConcurrentDispenser y bin/dispense-worker.php).
| - Sin RefreshDatabase: su transacción envolvente haría que los workers no
|   vieran los datos y ocultaría los bloqueos. Se usa DatabaseTruncation
|   (tablas vacías al empezar) y se trunca también al terminar para no dejar
|   datos confirmados a otras pruebas.
| - Grupo "concurrency": ./vendor/bin/pest --group=concurrency
*/

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Domain\Dispensing\DispenseService;
use App\Domain\Inventory\StockService;
use App\Enums\DispensationStatus;
use App\Enums\KardexType;
use App\Models\Dispensation;
use App\Models\DispensationItemLot;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrentDispenser;

uses(DatabaseTruncation::class)->group('concurrency');

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

/**
 * Entrada de stock por el servicio real (genera kardex ENTRADA), así la suma
 * del kardex debe cuadrar con stocks.quantity al final.
 */
function receiveStock(Warehouse $warehouse, Product $product, int $daysToExpire, int $quantity): Lot
{
    $lot = Lot::factory()->for($product)->expiresInDays($daysToExpire)->create();
    $regente = User::factory()->regente()->create();

    DB::transaction(fn () => app(StockService::class)->increase($warehouse->id, $lot, $quantity, KardexType::Entrada, $regente));

    return $lot;
}

/**
 * Una prescripción vigente con una línea por producto.
 *
 * @param  list<Product>  $products
 * @return array{0: Prescription, 1: list<PrescriptionItem>}
 */
function activePrescription(array $products, int $prescribed): array
{
    $prescription = Prescription::factory()->create();
    $items = array_map(fn (Product $product) => PrescriptionItem::factory()->create([
        'prescription_id' => $prescription->id,
        'product_id' => $product->id,
        'quantity_prescribed' => $prescribed,
    ]), $products);

    return [$prescription, $items];
}

/**
 * @param  list<array{0: PrescriptionItem, 1: int}>  $lines
 * @return array<string, mixed>
 */
function dispenseJob(User $user, Prescription $prescription, Warehouse $warehouse, array $lines, ?string $key = null): array
{
    return [
        'user_id' => $user->id,
        'patient_id' => $prescription->patient_id,
        'prescription_id' => $prescription->id,
        'warehouse_id' => $warehouse->id,
        'items' => array_map(fn (array $line) => [$line[0]->id, $line[1]], $lines),
        'idempotency_key' => $key ?? (string) Str::uuid(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return array<string, int> outcome => cantidad de procesos
 */
function outcomes(array $results): array
{
    $counts = array_count_values(array_map(fn (array $r) => (string) $r['outcome'], $results));
    ksort($counts);

    return $counts;
}

function stockOf(Warehouse $warehouse, Lot $lot): int
{
    return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('lot_id', $lot->id)->value('quantity');
}

it('RN-03: 8 procesos simultáneos por la ÚLTIMA unidad — exactamente 1 sale y el stock queda en 0', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $lot = receiveStock($warehouse, $product, 30, 1);

    // Prescripciones distintas: la única disputa es el stock, no el saldo de la prescripción.
    $jobs = [];
    foreach (range(1, 8) as $i) {
        [$prescription, [$item]] = activePrescription([$product], 1);
        $jobs[] = dispenseJob(User::factory()->auxiliar()->create(), $prescription, $warehouse, [[$item, 1]]);
    }

    $results = ConcurrentDispenser::run($jobs);

    expect(outcomes($results))->toBe(['OK' => 1, 'STOCK_INSUFICIENTE' => 7])
        ->and(stockOf($warehouse, $lot))->toBe(0)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(1)
        ->and(Dispensation::query()->count())->toBe(1)
        ->and((int) DispensationItemLot::query()->sum('quantity'))->toBe(1)
        ->and((int) PrescriptionItem::query()->sum('quantity_dispensed'))->toBe(1)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-02/RN-03: stock 5 en dos lotes y 10 procesos piden 1 — salen exactamente 5, en orden FEFO', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $late = receiveStock($warehouse, $product, 90, 2);
    $early = receiveStock($warehouse, $product, 15, 3);

    $jobs = [];
    foreach (range(1, 10) as $i) {
        [$prescription, [$item]] = activePrescription([$product], 1);
        $jobs[] = dispenseJob(User::factory()->auxiliar()->create(), $prescription, $warehouse, [[$item, 1]]);
    }

    $results = ConcurrentDispenser::run($jobs);

    expect(outcomes($results))->toBe(['OK' => 5, 'STOCK_INSUFICIENTE' => 5])
        ->and(stockOf($warehouse, $early))->toBe(0)
        ->and(stockOf($warehouse, $late))->toBe(0)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(5)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    // FEFO bajo concurrencia: el lote que vence después solo empieza a salir
    // cuando el que vence antes ya se agotó.
    $outflows = KardexMovement::query()->where('type', KardexType::SalidaDispensacion);
    $lastEarly = (clone $outflows)->where('lot_id', $early->id)->max('id');
    $firstLate = (clone $outflows)->where('lot_id', $late->id)->min('id');
    expect($lastEarly)->toBeLessThan($firstLate);
});

it('RN-09: 8 procesos con la MISMA Idempotency-Key — una sola dispensación y una sola salida', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $lot = receiveStock($warehouse, $product, 30, 10);
    [$prescription, [$item]] = activePrescription([$product], 10);
    $user = User::factory()->auxiliar()->create();
    $key = (string) Str::uuid();

    $results = ConcurrentDispenser::run(array_fill(0, 8, dispenseJob($user, $prescription, $warehouse, [[$item, 2]], $key)));

    $ids = array_unique(array_map(fn (array $r) => $r['dispensation_id'] ?? null, $results));
    $created = array_filter($results, fn (array $r) => ($r['replayed'] ?? null) === false);

    expect(outcomes($results))->toBe(['OK' => 8])
        ->and($ids)->toHaveCount(1)
        ->and($created)->toHaveCount(1)
        ->and(Dispensation::query()->count())->toBe(1)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(1)
        ->and(stockOf($warehouse, $lot))->toBe(8)
        ->and($item->fresh()?->quantity_dispensed)->toBe(2)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-09: 8 procesos con la MISMA clave pidiendo TODO el saldo — todos reciben la misma dispensación, ninguno PRESCRIPCION_*', function () {
    // Los reintentos esperan el bloqueo de la prescripción; cuando lo
    // obtienen, el primero ya entregó todo. Deben recibir el replay, no un
    // error de saldo agotado (por eso se revisa la clave tras el bloqueo).
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $lot = receiveStock($warehouse, $product, 30, 10);
    [$prescription, [$item]] = activePrescription([$product], 2);
    $user = User::factory()->auxiliar()->create();
    $key = (string) Str::uuid();

    $results = ConcurrentDispenser::run(array_fill(0, 8, dispenseJob($user, $prescription, $warehouse, [[$item, 2]], $key)));

    $ids = array_unique(array_map(fn (array $r) => $r['dispensation_id'] ?? null, $results));
    expect(outcomes($results))->toBe(['OK' => 8])
        ->and($ids)->toHaveCount(1)
        ->and(Dispensation::query()->count())->toBe(1)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(1)
        ->and(stockOf($warehouse, $lot))->toBe(8)
        ->and($item->fresh()?->quantity_dispensed)->toBe(2);
});

it('sin deadlocks: dispensaciones de dos productos con líneas en orden inverso terminan todas', function () {
    $warehouse = Warehouse::factory()->create();
    [$a, $b] = [Product::factory()->create(), Product::factory()->create()];
    $lotA = receiveStock($warehouse, $a, 30, 20);
    $lotB = receiveStock($warehouse, $b, 30, 20);

    // En la mitad de las prescripciones la línea de B se creó primero (id
    // menor) y además se pide en orden inverso: si las existencias se
    // bloquearan por orden de línea o de solicitud, y no por product_id,
    // habría ciclos de espera (A espera a B y B a A).
    $jobs = [];
    foreach (range(1, 8) as $i) {
        $products = $i % 2 === 0 ? [$a, $b] : [$b, $a];
        [$prescription, $items] = activePrescription($products, 1);
        $jobs[] = dispenseJob(User::factory()->auxiliar()->create(), $prescription, $warehouse, [[$items[0], 1], [$items[1], 1]]);
    }

    $deadlocksBefore = ConcurrentDispenser::deadlocksDetected();
    $results = ConcurrentDispenser::run($jobs);

    // DB::transaction(..., 3) reintentaría tras un deadlock y la prueba
    // pasaría igual: por eso se mira también el contador de PostgreSQL.
    expect(ConcurrentDispenser::deadlocksDetected() - $deadlocksBefore)->toBe(0)
        ->and(outcomes($results))->toBe(['OK' => 8])
        ->and(stockOf($warehouse, $lotA))->toBe(12)
        ->and(stockOf($warehouse, $lotB))->toBe(12)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(16)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-04: 8 procesos piden 1 sobre la MISMA prescripción de 5 (stock sobrado) — salen exactamente 5', function () {
    // La disputa es el saldo de la línea, no el stock (hay 20). La
    // prescripción tiene una segunda línea sin entregar para que NO pase a
    // COMPLETADA al llegar a 5/5: así los rechazados ven el saldo agotado
    // (PRESCRIPCION_EXCEDIDA). Con una sola línea ver la prueba siguiente.
    $warehouse = Warehouse::factory()->create();
    [$a, $b] = [Product::factory()->create(), Product::factory()->create()];
    receiveStock($warehouse, $a, 30, 20);
    [$prescription, [$itemA]] = activePrescription([$a, $b], 5);
    $user = User::factory()->auxiliar()->create();

    $jobs = [];
    foreach (range(1, 8) as $i) {
        $jobs[] = dispenseJob($user, $prescription, $warehouse, [[$itemA, 1]]);
    }

    $deadlocksBefore = ConcurrentDispenser::deadlocksDetected();
    $results = ConcurrentDispenser::run($jobs);

    // Antes de la corrección de la revisión de Fase 2 salía OK=1 y 7
    // deadlocks (40P01): el INSERT de la dispensación tomaba FOR KEY SHARE
    // sobre la prescripción y luego todos pedían FOR UPDATE sobre ella.
    expect(ConcurrentDispenser::deadlocksDetected() - $deadlocksBefore)->toBe(0)
        ->and(outcomes($results))->toBe(['OK' => 5, 'PRESCRIPCION_EXCEDIDA' => 3])
        ->and($itemA->fresh()?->quantity_dispensed)->toBe(5)
        ->and(Dispensation::query()->count())->toBe(5)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(5)
        ->and((int) Stock::query()->where('product_id', $a->id)->sum('quantity'))->toBe(15)
        ->and($prescription->fresh()?->status->value)->toBe('ACTIVA')
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-04/S-39: con una sola línea, al completarse la prescripción los demás reciben PRESCRIPCION_NO_VIGENTE', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    receiveStock($warehouse, $product, 30, 20);
    [$prescription, [$item]] = activePrescription([$product], 5);
    $user = User::factory()->auxiliar()->create();

    $jobs = [];
    foreach (range(1, 8) as $i) {
        $jobs[] = dispenseJob($user, $prescription, $warehouse, [[$item, 1]]);
    }

    $deadlocksBefore = ConcurrentDispenser::deadlocksDetected();
    $results = ConcurrentDispenser::run($jobs);

    // El 5.º deja la prescripción COMPLETADA (S-33) y los que esperaban el
    // bloqueo de la prescripción ven ese estado (S-39): nunca hay un 6.º OK.
    expect(ConcurrentDispenser::deadlocksDetected() - $deadlocksBefore)->toBe(0)
        ->and(outcomes($results))->toBe(['OK' => 5, 'PRESCRIPCION_NO_VIGENTE' => 3])
        ->and($item->fresh()?->quantity_dispensed)->toBe(5)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(5)
        ->and($prescription->fresh()?->status->value)->toBe('COMPLETADA')
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-05: dos regentes autorizan a la vez el mismo controlado — exactamente 1 autoriza y una sola salida', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->controlled()->create();
    $lot = receiveStock($warehouse, $product, 30, 10);
    [$prescription, [$item]] = activePrescription([$product], 5);
    $auxiliar = User::factory()->auxiliar()->create();

    $pending = app(DispenseService::class)->create(
        $auxiliar,
        new DispenseData($prescription->patient_id, $prescription->id, $warehouse->id, [new DispenseItemData($item->id, 2)]),
        (string) Str::uuid(),
    )->dispensation;
    expect($pending->status)->toBe(DispensationStatus::PendienteAutorizacion);

    [$regenteA, $regenteB] = [User::factory()->regente()->create(), User::factory()->regente()->create()];
    $results = ConcurrentDispenser::run([
        ['mode' => 'authorize', 'user_id' => $regenteA->id, 'dispensation_id' => $pending->id],
        ['mode' => 'authorize', 'user_id' => $regenteB->id, 'dispensation_id' => $pending->id],
    ]);

    $fresh = $pending->fresh();
    expect(outcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 1])
        ->and($fresh?->status)->toBe(DispensationStatus::Completada)
        ->and($fresh?->authorized_by)->toBeIn([$regenteA->id, $regenteB->id])
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(1)
        ->and((int) DispensationItemLot::query()->sum('quantity'))->toBe(2)
        ->and(stockOf($warehouse, $lot))->toBe(8)
        ->and($item->fresh()?->quantity_dispensed)->toBe(2)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('control: SIN FOR UPDATE el CHECK de la BD frena la sobreventa (la prueba sí detecta el problema)', function () {
    // Los workers "unsafe" quitan todos los bloqueos y esperan tras cada
    // lectura de stocks: todos leen saldo 1 y todos intentan descontar. Solo
    // la defensa de la BD (CHECK quantity >= 0) puede evitar la sobreventa.
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $lot = receiveStock($warehouse, $product, 30, 1);

    $jobs = [];
    foreach (range(1, 5) as $i) {
        [$prescription, [$item]] = activePrescription([$product], 1);
        $jobs[] = dispenseJob(User::factory()->auxiliar()->create(), $prescription, $warehouse, [[$item, 1]]) + ['unsafe' => true];
    }

    $results = ConcurrentDispenser::run($jobs);
    $counts = outcomes($results);

    expect($counts['OK'] ?? 0)->toBe(1)
        ->and($counts['CHECK_VIOLATION'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(array_keys($counts))->each->toBeIn(['OK', 'CHECK_VIOLATION', 'STOCK_INSUFICIENTE'])
        ->and(stockOf($warehouse, $lot))->toBe(0)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaDispensacion)->count())->toBe(1)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});
