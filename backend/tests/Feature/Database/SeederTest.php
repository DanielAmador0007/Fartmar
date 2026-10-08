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
