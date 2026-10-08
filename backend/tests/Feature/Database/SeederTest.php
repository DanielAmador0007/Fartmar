<?php

/*
| Datos semilla: idempotentes, coherentes con el kardex y con los casos que
| pide el enunciado §6 (lote vencido, lote que vence en < 30 días, bajo mínimo,
| controlado, un usuario por rol).
*/

use App\Enums\Role;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Stock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array<string, int> */
function tableCounts(): array
{
    $tables = ['users', 'warehouses', 'products', 'lots', 'stocks', 'kardex_movements', 'stock_minimums', 'patients', 'prescriptions', 'prescription_items'];

    return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('es idempotente: ejecutarlo dos veces no duplica nada', function () {
    $this->seed(DatabaseSeeder::class);
    $first = tableCounts();

    $this->seed(DatabaseSeeder::class);

    expect(tableCounts())->toBe($first)
        ->and($first['warehouses'])->toBe(3)
        ->and($first['products'])->toBe(6)
        ->and($first['patients'])->toBe(3);
});

it('cada existencia inicial tiene su ENTRADA y saldo = suma del kardex', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (Stock::all() as $stock) {
        $sum = (int) KardexMovement::query()
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('lot_id', $stock->lot_id)
            ->sum(DB::raw('direction * quantity'));

        expect($sum)->toBe($stock->quantity);
    }

    expect(KardexMovement::query()->where('type', '<>', 'ENTRADA')->count())->toBe(0);
});

it('incluye los casos de prueba del enunciado', function () {
    $this->seed(DatabaseSeeder::class);
    $today = CarbonImmutable::now(config('fartmar.business_timezone'))->startOfDay();

    expect(Lot::query()->where('expires_at', '<', $today)->count())->toBeGreaterThanOrEqual(1)
        ->and(Lot::query()->whereBetween('expires_at', [$today, $today->addDays(29)])->count())->toBeGreaterThanOrEqual(1)
        ->and(Lot::query()->whereBetween('expires_at', [$today->addDays(30), $today->addDays(90)])->count())->toBeGreaterThanOrEqual(1);

    foreach (Role::cases() as $role) {
        expect(User::query()->where('role', $role->value)->exists())->toBeTrue();
    }
    expect(User::query()->where('role', Role::RegenteFarmacia->value)->count())->toBe(2);

    $belowMinimum = DB::select(<<<'SQL'
        SELECT m.warehouse_id, m.product_id
        FROM stock_minimums m
        LEFT JOIN stocks s ON s.warehouse_id = m.warehouse_id AND s.product_id = m.product_id
        GROUP BY m.warehouse_id, m.product_id, m.min_quantity
        HAVING COALESCE(SUM(s.quantity), 0) < m.min_quantity
        SQL);
    expect($belowMinimum)->not->toBeEmpty();

    $controlledPrescriptions = Prescription::query()
        ->where('status', 'ACTIVA')
        ->where('valid_until', '>=', $today)
        ->whereHas('items.product', fn ($q) => $q->where('is_controlled', true))
        ->count();
    expect($controlledPrescriptions)->toBeGreaterThanOrEqual(1)
        ->and(Patient::query()->whereHas('prescriptions')->count())->toBe(3);
});

/*
| Agregadas en la revisión de QA (Fase 1, cierre).
*/

it('el lote vencido y el controlado tienen existencias (sirven para probar RN-01 y RN-05)', function () {
    $this->seed(DatabaseSeeder::class);
    $today = CarbonImmutable::now(config('fartmar.business_timezone'))->startOfDay();

    $expiredWithStock = Stock::query()
        ->where('quantity', '>', 0)
        ->whereHas('lot', fn ($q) => $q->where('expires_at', '<', $today))
        ->exists();
    $controlledWithStock = Stock::query()
        ->where('quantity', '>', 0)
        ->whereHas('lot', fn ($q) => $q->where('expires_at', '>=', $today)->whereHas('product', fn ($p) => $p->where('is_controlled', true)))
        ->exists();

    expect($expiredWithStock)->toBeTrue()->and($controlledWithStock)->toBeTrue();
});

it('re-ejecutar el seeder no altera existencias ni lo ya dispensado', function () {
    $this->seed(DatabaseSeeder::class);
    $stocksBefore = Stock::query()->orderBy('id')->pluck('quantity', 'id')->all();
    $item = DB::table('prescription_items')->orderBy('id')->first();
    DB::update('UPDATE prescription_items SET quantity_dispensed = 2 WHERE id = ?', [$item->id]);

    $this->seed(DatabaseSeeder::class);

    expect(Stock::query()->orderBy('id')->pluck('quantity', 'id')->all())->toBe($stocksBefore)
        ->and(DB::table('prescription_items')->where('id', $item->id)->value('quantity_dispensed'))->toBe(2);
});

it('solo contiene datos sintéticos: dominio .test, documentos 99…, teléfonos 300000… y apellidos marcados', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (User::query()->pluck('email') as $email) {
        expect($email)->toEndWith('@fartmar.test');
    }

    foreach (Patient::all() as $patient) {
        expect($patient->document_number)->toStartWith('99')
            ->and($patient->phone)->toStartWith('300000')
            ->and($patient->last_name)->toMatch('/Ficti|Sint[eé]tic|Inventad|Demo|Prueba|Ejemplo/u');
    }

    // Ni el documento ni el teléfono aparecen en claro en la tabla.
    $raw = json_encode(DB::table('patients')->get(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('9900000001')->and($raw)->not->toContain('3000000001');
});
