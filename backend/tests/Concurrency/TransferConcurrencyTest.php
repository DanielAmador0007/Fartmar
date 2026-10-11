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
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\KardexType;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\KardexMovement;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
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

/**
 * Lotes despachados de un traslado, en orden de id (FEFO: el que vence antes primero).
 *
 * @return list<TransferItemLot>
 */
function dispatchedLotsOf(Transfer $transfer): array
{
    return TransferItemLot::query()
        ->whereIn('transfer_item_id', $transfer->items()->select('id'))
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * Job de recepción: $quantities en el mismo orden que dispatchedLotsOf().
 *
 * @param  list<TransferItemLot>  $lots
 * @param  list<int>  $quantities
 * @return array<string, mixed>
 */
function receiveJob(User $user, Transfer $transfer, array $lots, array $quantities, bool $unsafe = false): array
{
    return [
        'mode' => 'transfer-receive',
        'user_id' => $user->id,
        'transfer_id' => $transfer->id,
        'received' => array_map(fn (TransferItemLot $lot, int $q) => [$lot->id, $q], $lots, $quantities),
        'unsafe' => $unsafe,
    ];
}

/**
 * Lo que debe quedar tras UNA sola recepción con $quantities (la del ganador).
 *
 * @param  list<TransferItemLot>  $lots
 * @param  list<int>  $quantities
 */
function expectSingleReceipt(TransferScenario $s, Transfer $transfer, array $lots, array $quantities, int $receiverId): void
{
    $missing = 0;

    foreach ($lots as $i => $itemLot) {
        $q = $quantities[$i];
        $missing += $itemLot->quantity_dispatched - $q;

        // Una sola ENTRADA_TRASLADO por lote (ninguna si llegó 0), con lo que declaró el ganador.
        $entries = KardexMovement::query()->where('type', KardexType::EntradaTraslado)->where('lot_id', $itemLot->lot_id);
        expect((clone $entries)->count())->toBe($q > 0 ? 1 : 0)
            ->and((int) (clone $entries)->sum('quantity'))->toBe($q)
            ->and($itemLot->fresh()?->quantity_received)->toBe($q)
            ->and($s->stockAt($s->destination, $itemLot->lot))->toBe($q)
            ->and(TransferDiscrepancy::query()->where('transfer_item_lot_id', $itemLot->id)->count())
            ->toBe($itemLot->quantity_dispatched > $q ? 1 : 0);
    }

    $fresh = $transfer->fresh();
    expect($fresh?->status)->toBe($missing === 0 ? TransferStatus::Recibido : TransferStatus::RecibidoParcial)
        ->and($fresh?->received_by)->toBe($receiverId)
        ->and((int) TransferDiscrepancy::query()->sum('quantity_missing'))->toBe($missing)
        ->and(AuditLog::query()->where('action', 'transfer.received')->count())->toBe(1)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
}

it('RN-07: 4 procesos reciben a la vez el MISMO traslado con cantidades distintas — una sola recepción, una ENTRADA_TRASLADO por lote', function () {
    // FEFO: 2 u. del lote que vence a 10 días y 3 del de 90 días.
    $s = TransferScenario::make([[10, 2], [90, 10]]);
    $transfer = $s->dispatched(5);
    $lots = dispatchedLotsOf($transfer);
    expect($lots)->toHaveCount(2);

    // Cada proceso declara algo distinto: así se comprueba que lo que quedó
    // en la BD es EXACTAMENTE lo del ganador, sin mezclas entre procesos.
    $receipts = [
        [$s->auxiliar, [2, 3]],
        [$s->regente, [2, 1]],
        [$s->regente2, [0, 3]],
        [User::factory()->auxiliar()->create(), [1, 0]],
    ];

    $results = ConcurrentDispenser::run(array_map(
        fn (array $r) => receiveJob($r[0], $transfer, $lots, $r[1]),
        $receipts,
    ));

    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 3]);

    $winner = (int) array_search('OK', array_column($results, 'outcome'), true);
    [$receiver, $quantities] = $receipts[$winner];

    expect($results[$winner]['status'])->toBe($transfer->fresh()?->status->value);
    expectSingleReceipt($s, $transfer, $lots, $quantities, $receiver->id);

    // Conservación: 12 iniciales = origen + destino + faltante declarado.
    expect($s->totalAt($s->origin) + $s->totalAt($s->destination) + (int) TransferDiscrepancy::query()->sum('quantity_missing'))->toBe(12);
});

/**
 * Traslado de 5 u. despachado y recibido parcialmente (3 de 5) por el
 * auxiliar: deja UNA discrepancia PENDIENTE de 2 u. Los regentes no
 * recibieron, así que pueden resolverla (S-60).
 */
function partiallyReceivedTransfer(TransferScenario $s): TransferDiscrepancy
{
    $transfer = $s->dispatched(5);
    app(TransferService::class)->receive($transfer, $s->auxiliar, [TransferItemLot::query()->sole()->id => 3]);

    return TransferDiscrepancy::query()->sole();
}

/**
 * @return array<string, mixed>
 */
function resolveJob(User $regente, TransferDiscrepancy $discrepancy, DiscrepancyResolution $action, bool $unsafe = false): array
{
    return [
        'mode' => 'discrepancy-resolve',
        'user_id' => $regente->id,
        'discrepancy_id' => $discrepancy->id,
        'action' => $action->value,
        'resolution' => "Resolución {$action->value} de prueba",
        'unsafe' => $unsafe,
    ];
}

it('S-50: 4 regentes resuelven a la vez la misma discrepancia con REINTEGRAR_ORIGEN — un solo AJUSTE (+)', function () {
    $s = TransferScenario::make([[30, 10]]);
    $discrepancy = partiallyReceivedTransfer($s);
    $regentes = [$s->regente, $s->regente2, User::factory()->regente()->create(), User::factory()->regente()->create()];

    $results = ConcurrentDispenser::run(array_map(
        fn (User $u) => resolveJob($u, $discrepancy, DiscrepancyResolution::ReintegrarOrigen),
        $regentes,
    ));

    $winner = (int) array_search('OK', array_column($results, 'outcome'), true);
    $fresh = $discrepancy->fresh();
    $adjustment = KardexMovement::query()->where('type', KardexType::Ajuste)->sole();

    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 3])
        ->and($fresh?->status)->toBe(DiscrepancyStatus::Resuelta)
        ->and($fresh?->resolved_by)->toBe($regentes[$winner]->id)
        ->and($adjustment->direction)->toBe(1)
        ->and($adjustment->quantity)->toBe(2)
        ->and($adjustment->user_id)->toBe($regentes[$winner]->id)
        ->and($adjustment->warehouse_id)->toBe($s->origin->id)
        // 10 - 5 despachadas + 2 reintegradas; destino 3.
        ->and($s->totalAt($s->origin))->toBe(7)
        ->and($s->totalAt($s->destination))->toBe(3)
        ->and(AuditLog::query()->where('action', 'transfer_discrepancy.resolved')->count())->toBe(1)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('S-50: REINTEGRAR_ORIGEN y PERDIDA a la vez sobre la misma discrepancia — gana una y el stock coincide con ella', function () {
    $s = TransferScenario::make([[30, 10]]);
    $discrepancy = partiallyReceivedTransfer($s);

    $results = ConcurrentDispenser::run([
        resolveJob($s->regente, $discrepancy, DiscrepancyResolution::ReintegrarOrigen),
        resolveJob($s->regente2, $discrepancy, DiscrepancyResolution::Perdida),
    ]);

    $fresh = $discrepancy->fresh();
    expect(transferOutcomes($results))->toBe(['OK' => 1, 'TRANSICION_INVALIDA' => 1])
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    if ($fresh?->resolution_action === DiscrepancyResolution::ReintegrarOrigen) {
        expect($fresh->resolved_by)->toBe($s->regente->id)
            ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(1)
            ->and($s->totalAt($s->origin))->toBe(7);
    } else {
        // PERDIDA: nada vuelve al origen (ni un AJUSTE "huérfano" del perdedor).
        expect($fresh?->resolution_action)->toBe(DiscrepancyResolution::Perdida)
            ->and($fresh?->resolved_by)->toBe($s->regente2->id)
            ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(0)
            ->and($s->totalAt($s->origin))->toBe(5);
    }
});

it('control: SIN FOR UPDATE el doble REINTEGRAR_ORIGEN lo frena la BD (discrepancia RESUELTA congelada)', function () {
    $s = TransferScenario::make([[30, 10]]);
    $discrepancy = partiallyReceivedTransfer($s);
    $regentes = [$s->regente, $s->regente2, User::factory()->regente()->create(), User::factory()->regente()->create()];

    $results = ConcurrentDispenser::run(array_map(
        fn (User $u) => resolveJob($u, $discrepancy, DiscrepancyResolution::ReintegrarOrigen, unsafe: true),
        $regentes,
    ));
    $counts = transferOutcomes($results);

    expect($counts['OK'] ?? 0)->toBe(1)
        ->and($counts['CHECK_VIOLATION'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(array_keys($counts))->each->toBeIn(['OK', 'CHECK_VIOLATION', 'TRANSICION_INVALIDA'])
        ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(1)
        ->and($s->totalAt($s->origin))->toBe(7)
        ->and($discrepancy->fresh()?->status)->toBe(DiscrepancyStatus::Resuelta)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});
it('control: SIN FOR UPDATE la doble recepción la frena la BD (la prueba sí detecta el problema)', function () {
    // Workers "unsafe": sin bloqueos y con pausa tras cada lectura de
    // traslados/stocks, todos leen EN_TRANSITO y pasan la máquina de estados
    // en PHP. Solo la BD (quantity_received se escribe una vez,
    // transfers_check_transition, saldo del kardex) evita la doble entrada.
    // Que aparezca CHECK_VIOLATION (y no solo TRANSICION_INVALIDA) prueba que
    // la carrera sí ocurrió; con el FOR UPDATE de producción la prueba
    // anterior obtiene 409 limpios.
    $s = TransferScenario::make([[10, 2], [90, 10]]);
    $transfer = $s->dispatched(5);
    $lots = dispatchedLotsOf($transfer);
    $users = [$s->auxiliar, $s->regente, $s->regente2, User::factory()->auxiliar()->create()];

    $results = ConcurrentDispenser::run(array_map(
        fn (User $u) => receiveJob($u, $transfer, $lots, [2, 3], unsafe: true),
        $users,
    ));
    $counts = transferOutcomes($results);

    expect($counts['OK'] ?? 0)->toBe(1)
        ->and($counts['CHECK_VIOLATION'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(array_keys($counts))->each->toBeIn(['OK', 'CHECK_VIOLATION', 'TRANSICION_INVALIDA']);

    $winner = (int) array_search('OK', array_column($results, 'outcome'), true);
    expectSingleReceipt($s, $transfer, $lots, [2, 3], $users[$winner]->id);
});

it('control: SIN FOR UPDATE el doble despacho lo frena la BD (no hay dos SALIDA_TRASLADO del mismo traslado)', function () {
    // Sin bloqueo, todos leen APROBADO y descuentan stock (hay de sobra: el
    // CHECK quantity >= 0 no ayuda). Lo frena la BD: el UNIQUE
    // (transfer_item_id, lot_id) de transfer_item_lots (FEFO elige el mismo
    // lote) y, si no, transfers_check_transition (EN_TRANSITO -> EN_TRANSITO).
    $s = TransferScenario::make([[30, 40]]);
    $transfer = approvedTransferFor($s, [$s->product->id => 5]);
    $users = [$s->auxiliar, $s->regente, $s->regente2, User::factory()->auxiliar()->create()];

    $results = ConcurrentDispenser::run(array_map(
        fn (User $u) => ['mode' => 'transfer-dispatch', 'user_id' => $u->id, 'transfer_id' => $transfer->id, 'unsafe' => true],
        $users,
    ));
    $counts = transferOutcomes($results);

    expect($counts['OK'] ?? 0)->toBe(1)
        ->and(($counts['CHECK_VIOLATION'] ?? 0) + ($counts['UNIQUE_VIOLATION'] ?? 0))->toBeGreaterThanOrEqual(1)
        ->and(array_keys($counts))->each->toBeIn(['OK', 'CHECK_VIOLATION', 'UNIQUE_VIOLATION', 'TRANSICION_INVALIDA'])
        ->and($transfer->fresh()?->status)->toBe(TransferStatus::EnTransito)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(1)
        ->and((int) TransferItemLot::query()->sum('quantity_dispatched'))->toBe(5)
        ->and($s->totalAt($s->origin))->toBe(35)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});
