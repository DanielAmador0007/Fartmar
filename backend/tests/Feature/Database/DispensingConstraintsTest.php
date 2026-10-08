<?php

/*
| Constraints de prescripciones y dispensaciones (RN-04, RN-05, RN-09).
*/

use App\Models\Lot;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dispensationRow(Prescription $prescription, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $prescription->patient_id,
        'prescription_id' => $prescription->id,
        'warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'COMPLETADA',
        'requires_authorization' => false,
        'created_by' => User::factory()->auxiliar()->create()->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('RN-04: rechaza dispensar más de lo prescrito (incluso acumulando parciales por SQL)', function () {
    $item = PrescriptionItem::factory()->create(['quantity_prescribed' => 10, 'quantity_dispensed' => 0]);

    // Parciales acumuladas válidas: 6 + 4 = 10.
    DB::update('UPDATE prescription_items SET quantity_dispensed = quantity_dispensed + 6 WHERE id = ?', [$item->id]);
    DB::update('UPDATE prescription_items SET quantity_dispensed = quantity_dispensed + 4 WHERE id = ?', [$item->id]);

    expectDbRejects(
        fn () => DB::update('UPDATE prescription_items SET quantity_dispensed = quantity_dispensed + 1 WHERE id = ?', [$item->id]),
        'prescription_items_dispensed_le_prescribed',
    );

    expect($item->fresh()?->quantity_dispensed)->toBe(10);
});

it('RN-04: rechaza cantidades prescritas no positivas y dispensadas negativas', function () {
    $item = PrescriptionItem::factory()->create();

    expectDbRejects(
        fn () => DB::update('UPDATE prescription_items SET quantity_prescribed = 0 WHERE id = ?', [$item->id]),
        'prescription_items_prescribed_positive',
    );
    expectDbRejects(
        fn () => DB::update('UPDATE prescription_items SET quantity_dispensed = -1 WHERE id = ?', [$item->id]),
        'prescription_items_dispensed_non_negative',
    );
});

it('rechaza prescripciones con vigencia anterior a la emisión o estado inválido', function () {
    $prescription = Prescription::factory()->create();

    expectDbRejects(
        fn () => DB::update('UPDATE prescriptions SET valid_until = issued_at::date - 1 WHERE id = ?', [$prescription->id]),
        'prescriptions_valid_range',
    );
    expectDbRejects(
        fn () => DB::update("UPDATE prescriptions SET status = 'VIGENTISIMA' WHERE id = ?", [$prescription->id]),
        'prescriptions_status_check',
    );
});

it('rechaza estados de dispensación fuera de la lista', function () {
    $prescription = Prescription::factory()->create();

    expectDbRejects(
        fn () => DB::table('dispensations')->insert(dispensationRow($prescription, ['status' => 'ENTREGADA'])),
        'dispensations_status_check',
    );
});

it('RN-09: rechaza una idempotency_key repetida', function () {
    $prescription = Prescription::factory()->create();
    DB::table('dispensations')->insert(dispensationRow($prescription, ['idempotency_key' => 'clave-1']));

    expectDbRejects(
        fn () => DB::table('dispensations')->insert(dispensationRow($prescription, ['idempotency_key' => 'clave-1'])),
        'dispensations_idempotency_key_unique',
    );
});

it('RN-05: quien autoriza no puede ser quien creó la dispensación', function () {
    $prescription = Prescription::factory()->create();
    $regente = User::factory()->regente()->create();

    expectDbRejects(fn () => DB::table('dispensations')->insert(dispensationRow($prescription, [
        'requires_authorization' => true,
        'created_by' => $regente->id,
        'authorized_by' => $regente->id,
        'authorized_at' => now(),
    ])), 'dispensations_authorizer_differs');
});

it('RN-05: un controlado no puede quedar COMPLETADA sin autorización', function () {
    $prescription = Prescription::factory()->create();

    expectDbRejects(fn () => DB::table('dispensations')->insert(dispensationRow($prescription, [
        'requires_authorization' => true,
        'status' => 'COMPLETADA',
    ])), 'dispensations_controlled_needs_authorization');

    // Pendiente sin autorizar sí es válido.
    DB::table('dispensations')->insert(dispensationRow($prescription, [
        'requires_authorization' => true,
        'status' => 'PENDIENTE_AUTORIZACION',
    ]));
    expect(DB::table('dispensations')->count())->toBe(1);
});

it('RN-05: solo un regente_farmacia puede autorizar (trigger)', function () {
    $prescription = Prescription::factory()->create();
    $auxiliar = User::factory()->auxiliar()->create();

    expectDbRejects(fn () => DB::table('dispensations')->insert(dispensationRow($prescription, [
        'requires_authorization' => true,
        'authorized_by' => $auxiliar->id,
        'authorized_at' => now(),
    ])), 'debe tener rol regente_farmacia');
});

it('un rechazo exige quién, cuándo y motivo', function () {
    $prescription = Prescription::factory()->create();

    expectDbRejects(fn () => DB::table('dispensations')->insert(dispensationRow($prescription, [
        'status' => 'RECHAZADA',
        'rejected_by' => User::factory()->regente()->create()->id,
        'rejected_at' => now(),
    ])), 'dispensations_rejection_complete');
});

it('rechaza una prescripción que no pertenece al paciente de la dispensación', function () {
    $prescription = Prescription::factory()->create();
    $otherPatient = Patient::factory()->create();

    expectDbRejects(
        fn () => DB::table('dispensations')->insert(dispensationRow($prescription, ['patient_id' => $otherPatient->id])),
        'dispensations_prescription_id_patient_id_foreign',
    );
});

it('rechaza dispensar un lote de un producto distinto al prescrito', function () {
    $prescription = Prescription::factory()->create();
    $item = PrescriptionItem::factory()->create(['prescription_id' => $prescription->id]);
    $dispensationId = DB::table('dispensations')->insertGetId(dispensationRow($prescription));
    $dispensationItemId = DB::table('dispensation_items')->insertGetId([
        'dispensation_id' => $dispensationId,
        'prescription_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => 2,
    ]);
    $lotOfOtherProduct = Lot::factory()->create(['product_id' => Product::factory()->create()->id]);

    expectDbRejects(fn () => DB::table('dispensation_item_lots')->insert([
        'dispensation_item_id' => $dispensationItemId,
        'lot_id' => $lotOfOtherProduct->id,
        'product_id' => $item->product_id,
        'quantity' => 2,
    ]), 'dispensation_item_lots_lot_id_product_id_foreign');

    expectDbRejects(fn () => DB::table('dispensation_item_lots')->insert([
        'dispensation_item_id' => $dispensationItemId,
        'lot_id' => $lotOfOtherProduct->id,
        'product_id' => $lotOfOtherProduct->product_id,
        'quantity' => 2,
    ]), 'dispensation_item_lots_dispensation_item_id_product_id_foreign');

    expectDbRejects(fn () => DB::table('dispensation_items')->where('id', $dispensationItemId)->update(['quantity' => 0]), 'dispensation_items_quantity_positive');
});

/*
| Huecos detectados en la revisión de QA (Fase 1, cierre).
*/

/**
 * Crea una dispensación (cabecera) y devuelve su id.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertDispensation(Prescription $prescription, array $overrides = []): int
{
    return DB::table('dispensations')->insertGetId(dispensationRow($prescription, $overrides));
}

/**
 * @return array<string, mixed>
 */
function dispensationItemRow(int $dispensationId, PrescriptionItem $item, int $quantity = 1): array
{
    return [
        'dispensation_id' => $dispensationId,
        'prescription_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => $quantity,
    ];
}

it('RN-05: una línea de medicamento controlado no puede ir en una dispensación que no exige autorización', function () {
    $prescription = Prescription::factory()->create();
    $controlledItem = PrescriptionItem::factory()->create([
        'prescription_id' => $prescription->id,
        'product_id' => Product::factory()->controlled()->create()->id,
    ]);

    // Bug simulado del servicio: marca requires_authorization = false y la deja COMPLETADA sin autorizador.
    $dispensationId = insertDispensation($prescription, ['requires_authorization' => false, 'status' => 'COMPLETADA']);

    expectDbRejects(
        fn () => DB::table('dispensation_items')->insert(dispensationItemRow($dispensationId, $controlledItem)),
        'RN-05',
    );

    // Con requires_authorization = true la línea sí se acepta (queda pendiente de autorizar).
    $pendingId = insertDispensation($prescription, ['requires_authorization' => true, 'status' => 'PENDIENTE_AUTORIZACION']);
    DB::table('dispensation_items')->insert(dispensationItemRow($pendingId, $controlledItem));

    // ...y ya no se puede "apagar" la exigencia para saltarse la autorización.
    expectDbRejects(
        fn () => DB::table('dispensations')->where('id', $pendingId)->update(['requires_authorization' => false, 'status' => 'COMPLETADA']),
        'RN-05',
    );
    expect(DB::table('dispensations')->where('id', $pendingId)->value('status'))->toBe('PENDIENTE_AUTORIZACION');
});

it('RN-04: la línea dispensada debe pertenecer a la prescripción de la dispensación', function () {
    $prescription = Prescription::factory()->create();
    $otherPatientsItem = PrescriptionItem::factory()->create(); // prescripción de OTRO paciente
    $dispensationId = insertDispensation($prescription);

    expectDbRejects(
        fn () => DB::table('dispensation_items')->insert(dispensationItemRow($dispensationId, $otherPatientsItem)),
        'no pertenece a la prescripción',
    );

    // Tampoco se puede cambiar la prescripción de la cabecera dejando líneas huérfanas.
    $ownItem = PrescriptionItem::factory()->create(['prescription_id' => $prescription->id]);
    DB::table('dispensation_items')->insert(dispensationItemRow($dispensationId, $ownItem));
    $samePatientOtherPrescription = Prescription::factory()->create(['patient_id' => $prescription->patient_id]);

    expectDbRejects(
        fn () => DB::table('dispensations')->where('id', $dispensationId)->update(['prescription_id' => $samePatientOtherPrescription->id]),
        'no pertenecen a la prescripción',
    );
});
