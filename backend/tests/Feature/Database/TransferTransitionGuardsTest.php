<?php

/*
| A1 (revisión de la Fase 3): la BD valida el PASO de estado, no solo la fila
| final. Se escribe con SQL directo a propósito: la defensa debe funcionar
| aunque la aplicación tuviera un bug o alguien actualizara la BD a mano.
*/

use App\Domain\Transfers\TransferStateMachine;
use App\Enums\TransferStatus;
use App\Models\Lot;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Columnas (actor, fecha, motivo) que los CHECK exigen para cada estado.
 *
 * @return array<string, mixed>
 */
function guardColumnsFor(string $status, User $regente, User $auxiliar): array
{
    $requested = ['requested_at' => now()];
    $approved = $requested + ['approved_by' => $regente->id, 'approved_at' => now()];
    $dispatched = $approved + ['dispatched_by' => $auxiliar->id, 'dispatched_at' => now()];
    $received = $dispatched + ['received_by' => $auxiliar->id, 'received_at' => now()];

    return match ($status) {
        'BORRADOR' => [],
        'SOLICITADO' => $requested,
        'APROBADO' => $approved,
        'EN_TRANSITO' => $dispatched,
        'RECIBIDO', 'RECIBIDO_PARCIAL' => $received,
        'ANULADO' => ['cancelled_by' => $regente->id, 'cancelled_at' => now(), 'cancellation_reason' => 'Prueba de esquema'],
        default => throw new InvalidArgumentException($status),
    };
}

/**
 * Inserta un traslado directamente en $status (INSERT no es una transición).
 */
function guardTransferIn(string $status, User $requester, User $regente, User $auxiliar): int
{
    return DB::table('transfers')->insertGetId([
        'origin_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => $status,
        'requested_by' => $requester->id,
        'created_at' => now(),
        'updated_at' => now(),
    ] + guardColumnsFor($status, $regente, $auxiliar));
}

it('A1: la BD acepta exactamente las mismas transiciones que TransferStateMachine (7 × 7 pares)', function () {
    $requester = User::factory()->auxiliar()->create();
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $machine = new TransferStateMachine;
    $checked = 0;

    foreach (TransferStatus::cases() as $from) {
        foreach (TransferStatus::cases() as $to) {
            $id = guardTransferIn($from->value, $requester, $regente, $auxiliar);
            $columns = $from === $to
                ? ['notes' => 'Cambio sin transición']
                : guardColumnsFor($to->value, $regente, $auxiliar);

            $accepted = true;
            try {
                DB::transaction(fn () => DB::table('transfers')->where('id', $id)->update(['status' => $to->value] + $columns));
            } catch (QueryException $e) {
                $accepted = false;
                // Lo rechaza el trigger de transición, no un CHECK de la fila final.
                expect($e->getMessage())->toContain('transfers_check_transition');
            }

            $expected = $from === $to || $machine->canTransition($from, $to);
            expect($accepted)->toBe($expected, "{$from->value} -> {$to->value}");
            $checked++;
        }
    }

    expect($checked)->toBe(49);
});

it('A1: no se puede saltar de BORRADOR a RECIBIDO aunque la fila final sea coherente', function () {
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $id = guardTransferIn('BORRADOR', $auxiliar, $regente, $auxiliar);

    expectDbRejects(
        fn () => DB::table('transfers')->where('id', $id)->update(['status' => 'RECIBIDO'] + guardColumnsFor('RECIBIDO', $regente, $auxiliar)),
        'transición de traslado no permitida de BORRADOR a RECIBIDO',
    );

    expect(DB::table('transfers')->where('id', $id)->value('status'))->toBe('BORRADOR');
});

it('A1: un traslado RECIBIDO no se reabre ni se devuelve a EN_TRANSITO', function () {
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $id = guardTransferIn('RECIBIDO', $auxiliar, $regente, $auxiliar);

    expectDbRejects(fn () => DB::table('transfers')->where('id', $id)->update(['status' => 'EN_TRANSITO']), 'transfers_check_transition');
    expectDbRejects(fn () => DB::table('transfers')->where('id', $id)->update(['status' => 'BORRADOR']), 'transfers_check_transition');
});

/**
 * Discrepancia ya RESUELTA (PERDIDA) sobre un lote recibido parcialmente.
 */
function guardResolvedDiscrepancy(User $resolver): int
{
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $transferId = guardTransferIn('BORRADOR', $auxiliar, $regente, $auxiliar);
    $product = Product::factory()->create();
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => 4,
    ]);
    foreach (['SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO_PARCIAL'] as $status) {
        DB::table('transfers')->where('id', $transferId)->update(['status' => $status] + guardColumnsFor($status, $regente, $auxiliar));
    }
    $itemLotId = DB::table('transfer_item_lots')->insertGetId([
        'transfer_item_id' => $itemId,
        'lot_id' => Lot::factory()->create(['product_id' => $product->id])->id,
        'product_id' => $product->id,
        'quantity_dispatched' => 4,
        'quantity_received' => 3,
    ]);

    return DB::table('transfer_discrepancies')->insertGetId([
        'transfer_item_lot_id' => $itemLotId,
        'quantity_missing' => 1,
        'status' => 'RESUELTA',
        'resolution_action' => 'PERDIDA',
        'resolution' => 'Unidad rota en el transporte',
        'resolved_by' => $resolver->id,
        'resolved_at' => now(),
    ]);
}

it('A1: una discrepancia RESUELTA no vuelve a PENDIENTE ni cambia su resolución', function (Closure $change) {
    $resolver = User::factory()->regente()->create();
    $id = guardResolvedDiscrepancy($resolver);

    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->where('id', $id)->update($change()),
        'transfer_discrepancies_freeze_resolved',
    );

    expect(DB::table('transfer_discrepancies')->where('id', $id)->value('resolution_action'))->toBe('PERDIDA');
})->with([
    'volver a PENDIENTE' => [fn () => ['status' => 'PENDIENTE', 'resolution_action' => null, 'resolution' => null, 'resolved_by' => null, 'resolved_at' => null]],
    'cambiar la acción' => [fn () => ['resolution_action' => 'REINTEGRAR_ORIGEN']],
    'cambiar el texto' => [fn () => ['resolution' => 'Otro motivo']],
    'cambiar quién resolvió' => [fn () => ['resolved_by' => User::factory()->regente()->create()->id]],
    'cambiar cuándo' => [fn () => ['resolved_at' => now()->subDay()]],
    'cambiar lo faltante' => [fn () => ['quantity_missing' => 2]],
]);

it('A1: tocar solo updated_at de una discrepancia resuelta sí se permite', function () {
    $id = guardResolvedDiscrepancy(User::factory()->regente()->create());

    DB::table('transfer_discrepancies')->where('id', $id)->update(['updated_at' => now()]);

    expect(DB::table('transfer_discrepancies')->where('id', $id)->value('status'))->toBe('RESUELTA');
});

it('A1: las líneas de un traslado solo se crean, cambian o borran en BORRADOR', function () {
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $product = Product::factory()->create();
    $id = guardTransferIn('BORRADOR', $auxiliar, $regente, $auxiliar);

    // En BORRADOR: insertar, cambiar y borrar es válido.
    $itemId = DB::table('transfer_items')->insertGetId(['transfer_id' => $id, 'product_id' => $product->id, 'quantity_requested' => 5]);
    DB::table('transfer_items')->where('id', $itemId)->update(['quantity_requested' => 6]);
    DB::table('transfer_items')->where('id', $itemId)->delete();
    $itemId = DB::table('transfer_items')->insertGetId(['transfer_id' => $id, 'product_id' => $product->id, 'quantity_requested' => 5]);

    foreach (['SOLICITADO', 'APROBADO', 'EN_TRANSITO'] as $status) {
        DB::table('transfers')->where('id', $id)->update(['status' => $status] + guardColumnsFor($status, $regente, $auxiliar));

        expectDbRejects(fn () => DB::table('transfer_items')->insert([
            'transfer_id' => $id, 'product_id' => Product::factory()->create()->id, 'quantity_requested' => 1,
        ]), 'transfer_items_only_in_draft');
        expectDbRejects(
            fn () => DB::table('transfer_items')->where('id', $itemId)->update(['quantity_requested' => 50]),
            'transfer_items_only_in_draft',
        );
        expectDbRejects(fn () => DB::table('transfer_items')->where('id', $itemId)->delete(), 'transfer_items_only_in_draft');
    }

    expect(DB::table('transfer_items')->where('id', $itemId)->value('quantity_requested'))->toBe(5);
});

it('A1: no se puede mover una línea de un BORRADOR a un traslado ya solicitado', function () {
    $regente = User::factory()->regente()->create();
    $auxiliar = User::factory()->auxiliar()->create();
    $draft = guardTransferIn('BORRADOR', $auxiliar, $regente, $auxiliar);
    $requested = guardTransferIn('SOLICITADO', $auxiliar, $regente, $auxiliar);
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $draft, 'product_id' => Product::factory()->create()->id, 'quantity_requested' => 5,
    ]);

    expectDbRejects(
        fn () => DB::table('transfer_items')->where('id', $itemId)->update(['transfer_id' => $requested]),
        'transfer_items_only_in_draft',
    );
});
