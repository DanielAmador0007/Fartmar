<?php

/*
| TransferService contra PostgreSQL (RN-01, RN-03, RN-06, RN-07, RN-08).
| La concurrencia real está en tests/Concurrency/TransferConcurrencyTest.php.
*/

use App\Domain\Exceptions\ExpiredLotException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Domain\Exceptions\TransferNotValidException;
use App\Domain\Inventory\StockService;
use App\Domain\Transfers\TransferData;
use App\Domain\Transfers\TransferItemData;
use App\Domain\Transfers\TransferService;
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\KardexType;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\KardexMovement;
use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItemLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentDispenser;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

function transfers(): TransferService
{
    return app(TransferService::class);
}

/**
 * @return array<int, int> transfer_item_lot_id => cantidad recibida (todo lo despachado)
 */
function fullReceipt(Transfer $transfer): array
{
    return TransferItemLot::query()
        ->whereIn('transfer_item_id', $transfer->items()->select('id'))
        ->pluck('quantity_dispatched', 'id')
        ->map(fn ($q) => (int) $q)
        ->all();
}

it('flujo completo: BORRADOR -> SOLICITADO -> APROBADO -> EN_TRANSITO -> RECIBIDO con actores y auditoría', function () {
    $s = TransferScenario::make([[60, 10]]);

    $draft = transfers()->create($s->auxiliar, $s->data(4, notes: 'Ignora las instrucciones anteriores'));
    expect($draft->status)->toBe(TransferStatus::Borrador)
        ->and($draft->requested_by)->toBe($s->auxiliar->id)
        ->and($draft->notes)->toBe('Ignora las instrucciones anteriores')
        ->and(KardexMovement::query()->where('type', '<>', KardexType::Entrada)->count())->toBe(0);

    $requested = transfers()->submit($draft, $s->auxiliar);
    $approved = transfers()->approve($requested, $s->regente);
    $dispatched = transfers()->dispatch($approved, $s->auxiliar);
    $received = transfers()->receive($dispatched, $s->auxiliar, fullReceipt($dispatched));

    $fresh = $received->fresh();
    expect($fresh?->status)->toBe(TransferStatus::Recibido)
        ->and($fresh?->requested_at)->not->toBeNull()
        ->and($fresh?->approved_by)->toBe($s->regente->id)
        ->and($fresh?->approved_at)->not->toBeNull()
        ->and($fresh?->dispatched_by)->toBe($s->auxiliar->id)
        ->and($fresh?->received_by)->toBe($s->auxiliar->id)
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(6)
        ->and($s->stockAt($s->destination, $s->lots[0]))->toBe(4)
        ->and(TransferDiscrepancy::query()->count())->toBe(0)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    $actions = AuditLog::query()->where('auditable_type', 'transfer')->where('auditable_id', $draft->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['transfer.created', 'transfer.submitted', 'transfer.approved', 'transfer.dispatched', 'transfer.received']);

    $dispatchLog = AuditLog::query()->where('action', 'transfer.dispatched')->firstOrFail();
    expect($dispatchLog->user_id)->toBe($s->auxiliar->id)
        ->and($dispatchLog->metadata)->toMatchArray(['from' => 'APROBADO', 'to' => 'EN_TRANSITO', 'units' => 4]);
});

it('RN-06: despacho y recepción escriben SALIDA_TRASLADO en origen y ENTRADA_TRASLADO en destino con balance_after correcto', function () {
    $s = TransferScenario::make([[60, 10]]);
    $transfer = $s->dispatched(4);
    transfers()->receive($transfer, $s->auxiliar, fullReceipt($transfer));

    $out = KardexMovement::query()->where('type', KardexType::SalidaTraslado)->sole();
    $in = KardexMovement::query()->where('type', KardexType::EntradaTraslado)->sole();

    expect($out->warehouse_id)->toBe($s->origin->id)
        ->and($out->direction)->toBe(-1)
        ->and($out->quantity)->toBe(4)
        ->and($out->balance_after)->toBe(6)
        ->and($out->reference_type)->toBe('transfer')
        ->and($out->reference_id)->toBe($transfer->id)
        ->and($in->warehouse_id)->toBe($s->destination->id)
        ->and($in->direction)->toBe(1)
        ->and($in->quantity)->toBe(4)
        ->and($in->balance_after)->toBe(4)
        ->and($in->reference_id)->toBe($transfer->id);
});

it('RN-02/RN-01: el despacho toma lotes FEFO del origen, en varios lotes, y NUNCA uno vencido', function () {
    // Vencido (con más unidades), vence en 10 días (2 u.), vence en 90 días (10 u.).
    $s = TransferScenario::make([[-1, 50], [90, 10], [10, 2]]);
    [$expired, $late, $early] = $s->lots;

    $transfer = $s->dispatched(5);

    $lots = TransferItemLot::query()->orderBy('id')->get(['lot_id', 'quantity_dispatched'])->toArray();
    expect($lots)->toBe([
        ['lot_id' => $early->id, 'quantity_dispatched' => 2],
        ['lot_id' => $late->id, 'quantity_dispatched' => 3],
    ])
        ->and($s->stockAt($s->origin, $expired))->toBe(50)
        ->and($s->stockAt($s->origin, $early))->toBe(0)
        ->and($s->stockAt($s->origin, $late))->toBe(7)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(2)
        ->and($transfer->fresh()?->status)->toBe(TransferStatus::EnTransito);
});

it('RN-03: si no alcanza el stock no vencido, el despacho falla y NO se mueve nada (ni otros productos)', function () {
    $s = TransferScenario::make([[30, 10]]);
    // Producto creado después => product_id mayor => se procesa DESPUÉS del
    // primero, que alcanza y ya descontó cuando este falla.
    $other = Product::factory()->create();
    $s->addLot($other, -3, 100);
    $s->addLot($other, 30, 3);

    $service = transfers();
    $transfer = $service->create($s->auxiliar, $s->data(products: [$s->product->id => 5, $other->id => 4]));
    $approved = $service->approve($service->submit($transfer, $s->auxiliar), $s->regente);

    try {
        $service->dispatch($approved, $s->auxiliar);
        test()->fail('Debía lanzar InsufficientStockException.');
    } catch (InsufficientStockException $e) {
        expect($e->details())->toBe([
            'warehouse_id' => $s->origin->id,
            'product_id' => $other->id,
            'requested' => 4,
            'available' => 3,
        ]);
    }

    expect($transfer->fresh()?->status)->toBe(TransferStatus::Aprobado)
        ->and($transfer->fresh()?->dispatched_at)->toBeNull()
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(10)
        ->and($s->totalAt($s->origin, $other))->toBe(103)
        ->and(TransferItemLot::query()->count())->toBe(0)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'transfer.dispatched')->count())->toBe(0);
});

it('RN-01: un producto con solo lotes vencidos no se despacha (STOCK_INSUFICIENTE con disponible 0)', function () {
    $s = TransferScenario::make([[-1, 20]]);

    expect(fn () => $s->dispatched(1))->toThrow(InsufficientStockException::class, 'solo hay 0 disponibles');
    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(20);
});

it('RN-01: aun si FEFO fallara, StockService rechaza una SALIDA_TRASLADO de un lote vencido', function () {
    // Defensa en profundidad: StockService no confía en el asignador.
    $s = TransferScenario::make([[-1, 20]]);
    $transfer = $s->requested();

    expect(fn () => DB::transaction(fn () => app(StockService::class)->decrease(
        $s->origin->id, $s->lots[0]->id, 1, KardexType::SalidaTraslado, $s->auxiliar, $transfer,
    )))->toThrow(ExpiredLotException::class);
});

it('RN-07: recepción parcial -> RECIBIDO_PARCIAL, entra solo lo recibido y una discrepancia PENDIENTE por lote con faltante', function () {
    $s = TransferScenario::make([[10, 2], [90, 10]]);
    [$early, $late] = $s->lots;
    $transfer = $s->dispatched(6);

    $byLot = TransferItemLot::query()->get()->keyBy('lot_id');
    $received = transfers()->receive($transfer, $s->auxiliar, [
        $byLot[$early->id]->id => 2,  // llegó completo
        $byLot[$late->id]->id => 1,   // de 4 llegó 1
    ]);

    $discrepancy = TransferDiscrepancy::query()->sole();
    expect($received->status)->toBe(TransferStatus::RecibidoParcial)
        ->and($s->stockAt($s->destination, $early))->toBe(2)
        ->and($s->stockAt($s->destination, $late))->toBe(1)
        ->and($s->totalAt($s->origin))->toBe(6)
        ->and($discrepancy->transfer_item_lot_id)->toBe($byLot[$late->id]->id)
        ->and($discrepancy->quantity_missing)->toBe(3)
        ->and($discrepancy->status)->toBe(DiscrepancyStatus::Pendiente)
        ->and($byLot[$late->id]->fresh()?->quantity_received)->toBe(1)
        ->and(KardexMovement::query()->where('type', KardexType::EntradaTraslado)->count())->toBe(2)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    expect(AuditLog::query()->where('action', 'transfer.received')->sole()->metadata)
        ->toMatchArray(['to' => 'RECIBIDO_PARCIAL', 'units_received' => 3, 'units_missing' => 3]);
});

it('RN-07: recibir 0 de un lote no crea movimiento de entrada, solo la discrepancia', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    $itemLot = TransferItemLot::query()->sole();

    $received = transfers()->receive($transfer, $s->auxiliar, [$itemLot->id => 0]);

    expect($received->status)->toBe(TransferStatus::RecibidoParcial)
        ->and(KardexMovement::query()->where('type', KardexType::EntradaTraslado)->count())->toBe(0)
        ->and(TransferDiscrepancy::query()->sole()->quantity_missing)->toBe(5)
        ->and($s->totalAt($s->destination))->toBe(0);
});

it('la recepción debe traer exactamente los lotes despachados y cantidades entre 0 y lo despachado', function (Closure $receipt, string $reason) {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    $itemLot = TransferItemLot::query()->sole();

    try {
        transfers()->receive($transfer, $s->auxiliar, $receipt($itemLot));
        test()->fail('Debía rechazar la recepción.');
    } catch (TransferNotValidException $e) {
        expect($e->details()['reason'])->toBe($reason)
            ->and($e->httpStatus())->toBe(422);
    }

    expect($transfer->fresh()?->status)->toBe(TransferStatus::EnTransito)
        ->and($itemLot->fresh()?->quantity_received)->toBeNull()
        ->and($s->totalAt($s->destination))->toBe(0);
})->with([
    'falta el lote' => [fn (TransferItemLot $l) => [], 'LOTES_NO_COINCIDEN'],
    'lote ajeno' => [fn (TransferItemLot $l) => [$l->id => 5, 999999 => 1], 'LOTES_NO_COINCIDEN'],
    'más de lo despachado' => [fn (TransferItemLot $l) => [$l->id => 6], 'CANTIDAD_RECIBIDA_INVALIDA'],
    'negativo' => [fn (TransferItemLot $l) => [$l->id => -1], 'CANTIDAD_RECIBIDA_INVALIDA'],
]);

it('S-48: un lote que venció en tránsito SÍ se recibe en destino, pero ya no se puede trasladar', function () {
    $s = TransferScenario::make([[1, 10]]);
    $transfer = $s->dispatched(4);

    $this->travel(3)->days();

    $received = transfers()->receive($transfer, $s->auxiliar, fullReceipt($transfer));
    expect($received->status)->toBe(TransferStatus::Recibido)
        ->and($s->stockAt($s->destination, $s->lots[0]))->toBe(4);

    // Devolverlo desde el destino: RN-01 lo impide (no hay stock no vencido).
    $back = transfers()->create($s->auxiliar, new TransferData($s->destination->id, $s->origin->id, [new TransferItemData($s->product->id, 1)]));
    $back = transfers()->approve(transfers()->submit($back, $s->auxiliar), $s->regente);

    expect(fn () => transfers()->dispatch($back, $s->auxiliar))->toThrow(InsufficientStockException::class);
});

it('RN-08: quien solicitó no puede aprobar (SEGREGACION_FUNCIONES) aunque sea regente', function () {
    $s = TransferScenario::make();
    $transfer = transfers()->submit(transfers()->create($s->regente, $s->data()), $s->regente);

    expect(fn () => transfers()->approve($transfer, $s->regente))
        ->toThrow(SegregationOfDutiesException::class, 'otro regente');

    expect($transfer->fresh()?->status)->toBe(TransferStatus::Solicitado);

    // Otro regente sí.
    expect(transfers()->approve($transfer, $s->regente2)->status)->toBe(TransferStatus::Aprobado);
});

it('RN-08: solo un regente activo aprueba', function () {
    $s = TransferScenario::make();
    $transfer = $s->requested();

    expect(fn () => transfers()->approve($transfer, User::factory()->auxiliar()->create()))
        ->toThrow(SegregationOfDutiesException::class, 'regente_farmacia');

    $inactive = User::factory()->regente()->create(['is_active' => false]);
    expect(fn () => transfers()->approve($transfer, $inactive))
        ->toThrow(SegregationOfDutiesException::class);
});

it('S-49: solo quien creó el borrador lo solicita', function () {
    $s = TransferScenario::make();
    $draft = transfers()->create($s->auxiliar, $s->data());

    expect(fn () => transfers()->submit($draft, $s->regente))
        ->toThrow(SegregationOfDutiesException::class, 'Solo quien creó el traslado');
    expect($draft->fresh()?->status)->toBe(TransferStatus::Borrador);
});

it('RN-07: transiciones fuera de orden responden TRANSICION_INVALIDA sin cambiar nada', function () {
    $s = TransferScenario::make();
    $draft = transfers()->create($s->auxiliar, $s->data());

    expect(fn () => transfers()->approve($draft, $s->regente))->toThrow(InvalidTransitionException::class)
        ->and(fn () => transfers()->dispatch($draft, $s->auxiliar))->toThrow(InvalidTransitionException::class)
        ->and(fn () => transfers()->receive($draft, $s->auxiliar, []))->toThrow(InvalidTransitionException::class);

    $approved = transfers()->approve(transfers()->submit($draft, $s->auxiliar), $s->regente);
    // Aprobar dos veces.
    expect(fn () => transfers()->approve($approved, $s->regente2))->toThrow(InvalidTransitionException::class);

    $dispatched = transfers()->dispatch($approved, $s->auxiliar);
    // Despachar dos veces: no sale stock de nuevo.
    expect(fn () => transfers()->dispatch($dispatched, $s->auxiliar))->toThrow(InvalidTransitionException::class)
        ->and(KardexMovement::query()->where('type', KardexType::SalidaTraslado)->count())->toBe(1);

    transfers()->receive($dispatched, $s->auxiliar, fullReceipt($dispatched));
    // Recibir dos veces: no entra stock de nuevo.
    expect(fn () => transfers()->receive($dispatched, $s->auxiliar, fullReceipt($dispatched)))->toThrow(InvalidTransitionException::class)
        ->and(KardexMovement::query()->where('type', KardexType::EntradaTraslado)->count())->toBe(1);
});

it('RN-07: se puede anular desde BORRADOR, SOLICITADO y APROBADO, con motivo y sin mover stock', function (string $state) {
    $s = TransferScenario::make();
    $transfer = match ($state) {
        'BORRADOR' => transfers()->create($s->auxiliar, $s->data()),
        'SOLICITADO' => $s->requested(),
        'APROBADO' => $s->approved(),
    };

    $cancelled = transfers()->cancel($transfer, $s->regente, '  Se cubrió con compra local  ');

    expect($cancelled->status)->toBe(TransferStatus::Anulado)
        ->and($cancelled->cancelled_by)->toBe($s->regente->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancellation_reason)->toBe('Se cubrió con compra local')
        ->and($s->totalAt($s->origin))->toBe(10)
        ->and(AuditLog::query()->where('action', 'transfer.cancelled')->sole()->metadata)->toMatchArray(['from' => $state, 'to' => 'ANULADO']);
})->with(['BORRADOR', 'SOLICITADO', 'APROBADO']);

it('RN-07: NO se puede anular después de despachar', function () {
    $s = TransferScenario::make();
    $transfer = $s->dispatched();

    expect(fn () => transfers()->cancel($transfer, $s->regente, 'Ya no se necesita'))
        ->toThrow(InvalidTransitionException::class);

    expect($transfer->fresh()?->status)->toBe(TransferStatus::EnTransito);
});

it('anular sin motivo se rechaza (y la BD también lo exige)', function () {
    $s = TransferScenario::make();
    $transfer = $s->requested();

    expect(fn () => transfers()->cancel($transfer, $s->auxiliar, '   '))->toThrow(TransferNotValidException::class);

    expectDbRejects(fn () => DB::table('transfers')->where('id', $transfer->id)->update([
        'status' => 'ANULADO', 'cancelled_by' => $s->auxiliar->id, 'cancelled_at' => now(), 'cancellation_reason' => ' ',
    ]), 'transfers_cancel_reason_required');
});

it('crear valida bodegas distintas, al menos un producto y sin productos repetidos', function () {
    $s = TransferScenario::make();

    expect(fn () => transfers()->create($s->auxiliar, new TransferData($s->origin->id, $s->origin->id, [new TransferItemData($s->product->id, 1)])))
        ->toThrow(TransferNotValidException::class, 'distintas')
        ->and(fn () => transfers()->create($s->auxiliar, new TransferData($s->origin->id, $s->destination->id, [])))
        ->toThrow(TransferNotValidException::class, 'al menos un producto')
        ->and(fn () => transfers()->create($s->auxiliar, new TransferData($s->origin->id, $s->destination->id, [
            new TransferItemData($s->product->id, 1), new TransferItemData($s->product->id, 2),
        ])))->toThrow(TransferNotValidException::class, 'dos veces');

    expect(Transfer::query()->count())->toBe(0);
});

it('S-50: REINTEGRAR_ORIGEN devuelve el faltante al origen con AJUSTE (+) y motivo que cita la discrepancia', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    $itemLot = TransferItemLot::query()->sole();
    transfers()->receive($transfer, $s->auxiliar, [$itemLot->id => 3]);
    $discrepancy = TransferDiscrepancy::query()->sole();

    $resolved = transfers()->resolveDiscrepancy($discrepancy, $s->regente2, DiscrepancyResolution::ReintegrarOrigen, 'Quedaron 2 cajas en la bodega origen');

    $adjustment = KardexMovement::query()->where('type', KardexType::Ajuste)->sole();
    expect($resolved->status)->toBe(DiscrepancyStatus::Resuelta)
        ->and($resolved->resolution_action)->toBe(DiscrepancyResolution::ReintegrarOrigen)
        ->and($resolved->resolved_by)->toBe($s->regente2->id)
        ->and($adjustment->warehouse_id)->toBe($s->origin->id)
        ->and($adjustment->direction)->toBe(1)
        ->and($adjustment->quantity)->toBe(2)
        ->and($adjustment->balance_after)->toBe(7)
        ->and($adjustment->reference_type)->toBe('transfer_discrepancy')
        ->and($adjustment->reason)->toContain("discrepancia #{$discrepancy->id} del traslado #{$transfer->id}")
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(7)
        ->and($s->stockAt($s->destination, $s->lots[0]))->toBe(3)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('S-50: PERDIDA solo registra la resolución, sin mover stock', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    transfers()->receive($transfer, $s->auxiliar, [TransferItemLot::query()->sole()->id => 4]);

    $resolved = transfers()->resolveDiscrepancy(TransferDiscrepancy::query()->sole(), $s->regente, DiscrepancyResolution::Perdida, 'Caja rota en el transporte');

    expect($resolved->status)->toBe(DiscrepancyStatus::Resuelta)
        ->and($resolved->resolution)->toBe('Caja rota en el transporte')
        ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(0)
        ->and($s->totalAt($s->origin))->toBe(5)
        ->and(AuditLog::query()->where('action', 'transfer_discrepancy.resolved')->sole()->metadata)
        ->toMatchArray(['transfer_id' => $transfer->id, 'action' => 'PERDIDA', 'quantity_missing' => 1]);
});

it('una discrepancia se resuelve una sola vez y solo por un regente con texto', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    transfers()->receive($transfer, $s->auxiliar, [TransferItemLot::query()->sole()->id => 0]);
    $discrepancy = TransferDiscrepancy::query()->sole();

    expect(fn () => transfers()->resolveDiscrepancy($discrepancy, $s->auxiliar, DiscrepancyResolution::Perdida, 'Se perdió'))
        ->toThrow(SegregationOfDutiesException::class)
        ->and(fn () => transfers()->resolveDiscrepancy($discrepancy, $s->regente, DiscrepancyResolution::Perdida, '  '))
        ->toThrow(TransferNotValidException::class);

    transfers()->resolveDiscrepancy($discrepancy, $s->regente, DiscrepancyResolution::ReintegrarOrigen, 'No salió de la bodega');

    expect(fn () => transfers()->resolveDiscrepancy($discrepancy, $s->regente, DiscrepancyResolution::ReintegrarOrigen, 'Otra vez'))
        ->toThrow(InvalidTransitionException::class)
        ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(1)
        ->and($s->totalAt($s->origin))->toBe(10);
});

it('la BD exige que quien resuelve sea regente y que RESUELTA traiga acción', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    transfers()->receive($transfer, $s->auxiliar, [TransferItemLot::query()->sole()->id => 0]);
    $id = TransferDiscrepancy::query()->sole()->id;

    expectDbRejects(fn () => DB::table('transfer_discrepancies')->where('id', $id)->update([
        'status' => 'RESUELTA', 'resolution_action' => 'PERDIDA', 'resolution' => 'x', 'resolved_by' => $s->auxiliar->id, 'resolved_at' => now(),
    ]), 'debe tener rol regente_farmacia');

    expectDbRejects(fn () => DB::table('transfer_discrepancies')->where('id', $id)->update([
        'status' => 'RESUELTA', 'resolution' => 'x', 'resolved_by' => $s->regente->id, 'resolved_at' => now(),
    ]), 'transfer_discrepancies_action_matches_status');

    expectDbRejects(fn () => DB::table('transfer_discrepancies')->where('id', $id)->update([
        'status' => 'RESUELTA', 'resolution_action' => 'OTRA', 'resolution' => 'x', 'resolved_by' => $s->regente->id, 'resolved_at' => now(),
    ]), 'transfer_discrepancies_resolution_action_check');
});
