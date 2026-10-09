<?php

/*
| Concurrencia REAL de traslados (RN-03, RN-06, RN-07, RN-08).
|
| Mismo patrón que DispenseConcurrencyTest: procesos PHP separados, cada uno
| con su conexión a PostgreSQL, soltados a la vez con una barrera
| (Tests\Support\ConcurrentDispenser + bin/dispense-worker.php en modos
| transfer-*). Sin RefreshDatabase: DatabaseTruncation y truncado al final.
| Grupo "concurrency".
*/

use App\Domain\Transfers\TransferService;
use App\Enums\KardexType;
use App\Enums\TransferStatus;
use App\Models\KardexMovement;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\TransferItemLot;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\Support\ConcurrentDispenser;
use Tests\Support\TransferScenario;

uses(DatabaseTruncation::class)->group('concurrency');

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

/**
 * Traslado APROBADO desde la bodega origen del escenario.
 *
 * @param  array<int, int>  $products  product_id => cantidad
 */
function approvedTransferFor(TransferScenario $s, array $products): Transfer
{
    $service = app(TransferService::class);
    $draft = $service->create($s->auxiliar, $s->data(products: $products));

    return $service->approve($service->submit($draft, $s->auxiliar), $s->regente);
}

/**
 * Job de dispensación (modo "create" del worker) con una prescripción vigente
 * nueva: la única disputa es el stock, no el saldo de la prescripción.
 *
 * @param  list<Product>  $products  una línea de 1 unidad por producto, en este orden
 * @return array<string, mixed>
 */
function transferRaceDispenseJob(Warehouse $warehouse, array $products): array
{
    $prescription = Prescription::factory()->create();
    $items = array_map(fn (Product $product) => PrescriptionItem::factory()->create([
        'prescription_id' => $prescription->id,
        'product_id' => $product->id,
        'quantity_prescribed' => 1,
    ]), $products);

    return [
        'user_id' => User::factory()->auxiliar()->create()->id,
        'patient_id' => $prescription->patient_id,
        'prescription_id' => $prescription->id,
        'warehouse_id' => $warehouse->id,
        'items' => array_map(fn (PrescriptionItem $item) => [$item->id, 1], $items),
        'idempotency_key' => (string) Str::uuid(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return array<string, int>
 */
function transferOutcomes(array $results): array
{
    $counts = array_count_values(array_map(fn (array $r) => (string) $r['outcome'], $results));
    ksort($counts);

    return $counts;
}

it('RN-03: 4 despachos de traslado y 4 dispensaciones compiten por 3 unidades — salen exactamente 3', function () {
    $s = TransferScenario::make([[30, 3]]);

    $jobs = [];
    foreach (range(1, 4) as $i) {
        $transfer = approvedTransferFor($s, [$s->product->id => 1]);
        $jobs[] = ['mode' => 'transfer-dispatch', 'user_id' => $s->auxiliar->id, 'transfer_id' => $transfer->id];
        $jobs[] = transferRaceDispenseJob($s->origin, [$s->product]);
    }

    $results = ConcurrentDispenser::run($jobs);

    $outflows = KardexMovement::query()->whereIn('type', [KardexType::SalidaTraslado, KardexType::SalidaDispensacion]);
    $dispatched = Transfer::query()->where('status', TransferStatus::EnTransito)->count();

    expect(transferOutcomes($results))->toBe(['OK' => 3, 'STOCK_INSUFICIENTE' => 5])
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(0)
        ->and((clone $outflows)->count())->toBe(3)
        ->and((int) (clone $outflows)->sum('quantity'))->toBe(3)
        // Cada traslado que ganó quedó EN_TRANSITO con su lote; los demás siguen APROBADO sin lotes.
        ->and(TransferItemLot::query()->count())->toBe($dispatched)
        ->and(Transfer::query()->where('status', TransferStatus::Aprobado)->count())->toBe(4 - $dispatched)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-07: 4 procesos despachan el MISMO traslado — uno lo despacha, los demás TRANSICION_INVALIDA, una sola salida', function () {
    $s = TransferScenario::make([[10, 2], [60, 10]]);
    $transfer = approvedTransferFor($s, [$s->product->id => 5]);
    $users = [$s->auxiliar, $s->regente, $s->regente2, User::factory()->auxiliar()->create()];

    $results = ConcurrentDispenser::run(array_map(
        fn (User $u) => ['mode' => 'transfer-dispatch', 'user_id' => $u->id, 'transfer_id' => $transfer->id],
        $users,
    ));

    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 3])
        ->and($transfer->fresh()?->status)->toBe(TransferStatus::EnTransito)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(2)
        ->and((int) TransferItemLot::query()->sum('quantity_dispatched'))->toBe(5)
        ->and($s->totalAt($s->origin))->toBe(7)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-08: dos regentes aprueban a la vez el mismo traslado — exactamente uno aprueba', function () {
    $s = TransferScenario::make();
    $service = app(TransferService::class);
    $transfer = $service->submit($service->create($s->auxiliar, $s->data()), $s->auxiliar);

    $results = ConcurrentDispenser::run([
        ['mode' => 'transfer-approve', 'user_id' => $s->regente->id, 'transfer_id' => $transfer->id],
        ['mode' => 'transfer-approve', 'user_id' => $s->regente2->id, 'transfer_id' => $transfer->id],
    ]);

    $fresh = $transfer->fresh();
    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 1])
        ->and($fresh?->status)->toBe(TransferStatus::Aprobado)
        ->and($fresh?->approved_by)->toBeIn([$s->regente->id, $s->regente2->id]);
});

it('RN-07: despachar y anular a la vez — gana uno y el estado final es coherente con el stock', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = approvedTransferFor($s, [$s->product->id => 4]);

    $results = ConcurrentDispenser::run([
        ['mode' => 'transfer-dispatch', 'user_id' => $s->auxiliar->id, 'transfer_id' => $transfer->id],
        ['mode' => 'transfer-cancel', 'user_id' => $s->regente->id, 'transfer_id' => $transfer->id, 'reason' => 'Se cubrió con compra local'],
    ]);

    $fresh = $transfer->fresh();
    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 1])
        ->and($fresh?->status)->toBeIn([TransferStatus::EnTransito, TransferStatus::Anulado])
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    if ($fresh?->status === TransferStatus::Anulado) {
        // Nunca un traslado anulado con stock fuera del origen.
        expect($s->totalAt($s->origin))->toBe(10)
            ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(0)
            ->and($fresh->dispatched_at)->toBeNull();
    } else {
        expect($s->totalAt($s->origin))->toBe(6)
            ->and($fresh?->cancelled_at)->toBeNull();
    }
});

it('sin deadlocks: traslados y dispensaciones de dos productos en orden inverso sobre la misma bodega', function () {
    $s = TransferScenario::make([[30, 40]]);
    $a = $s->product;
    $b = Product::factory()->create();
    $s->addLot($b, 30, 40);

    // Las líneas se crean en orden B, A en la mitad de los casos: si algo
    // bloqueara existencias por orden de línea y no por product_id, habría
    // ciclos de espera entre traslados y dispensaciones.
    $jobs = [];
    foreach (range(1, 4) as $i) {
        $order = $i % 2 === 0 ? [$a->id => 2, $b->id => 2] : [$b->id => 2, $a->id => 2];
        $transfer = approvedTransferFor($s, $order);
        $jobs[] = ['mode' => 'transfer-dispatch', 'user_id' => $s->auxiliar->id, 'transfer_id' => $transfer->id];
        $jobs[] = transferRaceDispenseJob($s->origin, $i % 2 === 0 ? [$a, $b] : [$b, $a]);
    }

    $deadlocksBefore = ConcurrentDispenser::deadlocksDetected();
    $results = ConcurrentDispenser::run($jobs);

    expect(ConcurrentDispenser::deadlocksDetected() - $deadlocksBefore)->toBe(0)
        ->and(transferOutcomes($results))->toBe(['OK' => 8])
        // 4 traslados × 2 u. + 4 dispensaciones × 1 u. = 12 por producto.
        ->and($s->totalAt($s->origin, $a))->toBe(28)
        ->and($s->totalAt($s->origin, $b))->toBe(28)
        ->and(Stock::query()->where('quantity', '<', 0)->count())->toBe(0)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});
