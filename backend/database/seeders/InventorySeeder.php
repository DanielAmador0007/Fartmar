<?php

namespace Database\Seeders;

use App\Domain\Inventory\StockService;
use App\Enums\KardexType;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMinimum;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Existencias iniciales y mínimos por bodega.
 *
 * Cada existencia inicial entra por StockService::increase(), que genera el
 * movimiento ENTRADA en el kardex con balance_after = cantidad (RN-06).
 * Idempotencia: si la existencia (bodega + lote) ya existe NO se toca, porque
 * el kardex es inmutable y cambiar el saldo exigiría un AJUSTE con motivo.
 */
class InventorySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: int}> [bodega, lote, cantidad] */
    public const INITIAL_STOCK = [
        ['FAR-CEN', 'L-ACE-2401', 30],   // lote vencido con existencias
        ['FAR-CEN', 'L-ACE-2402', 50],
        ['FAR-CEN', 'L-ACE-2403', 200],
        ['FAR-CEN', 'L-IBU-2401', 40],
        ['FAR-CEN', 'L-IBU-2402', 150],
        ['FAR-CEN', 'L-AMX-2401', 60],
        ['FAR-CEN', 'L-AMX-2402', 100],
        ['FAR-CEN', 'L-LOS-2401', 80],
        ['FAR-CEN', 'L-LOS-2402', 120],
        ['FAR-CEN', 'L-OME-2401', 30],
        ['FAR-CEN', 'L-OME-2402', 90],
        ['FAR-CEN', 'L-MOR-2401', 10],
        ['FAR-CEN', 'L-MOR-2402', 15],
        ['FAR-URG', 'L-ACE-2402', 20],
        ['FAR-URG', 'L-ACE-2403', 40],
        ['FAR-URG', 'L-IBU-2402', 30],
        ['FAR-URG', 'L-AMX-2402', 15],
        ['FAR-URG', 'L-OME-2402', 10],
        ['FAR-URG', 'L-MOR-2401', 5],
        ['BOD-HOS', 'L-ACE-2403', 100],
        ['BOD-HOS', 'L-IBU-2401', 3],
        ['BOD-HOS', 'L-AMX-2401', 25],
        ['BOD-HOS', 'L-LOS-2402', 40],
    ];

    /**
     * [bodega, producto, mínimo]. Quedan BAJO MÍNIMO: FAR-URG/IBU400 (30 < 50),
     * FAR-URG/MOR10 (5 < 10), BOD-HOS/IBU400 (3 < 20), BOD-HOS/OME20 (0 < 10).
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    public const MINIMUMS = [
        ['FAR-CEN', 'ACE500', 100],
        ['FAR-CEN', 'MOR10', 20],
        ['FAR-CEN', 'AMX500', 50],
        ['FAR-URG', 'ACE500', 30],
        ['FAR-URG', 'IBU400', 50],
        ['FAR-URG', 'MOR10', 10],
        ['BOD-HOS', 'IBU400', 20],
        ['BOD-HOS', 'OME20', 10],
    ];

    public function run(StockService $stockService): void
    {
        $warehouses = Warehouse::query()->pluck('id', 'code');
        $lots = Lot::query()->get()->keyBy('lot_number');
        $products = Product::query()->pluck('id', 'code');
        $receiver = User::query()->where('email', 'regente@fartmar.test')->firstOrFail();

        DB::transaction(function () use ($warehouses, $lots, $receiver, $stockService): void {
            foreach (self::INITIAL_STOCK as [$warehouseCode, $lotNumber, $quantity]) {
                $lot = $lots->get($lotNumber) ?? throw new \RuntimeException("Lote semilla inexistente: {$lotNumber}");
                $warehouseId = $warehouses[$warehouseCode];

                $exists = Stock::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('lot_id', $lot->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $stockService->increase(
                    $warehouseId,
                    $lot,
                    $quantity,
                    KardexType::Entrada,
                    $receiver,
                    reason: 'Inventario inicial (datos semilla)',
                );
            }
        });

        foreach (self::MINIMUMS as [$warehouseCode, $productCode, $minQuantity]) {
            StockMinimum::query()->updateOrCreate(
                ['warehouse_id' => $warehouses[$warehouseCode], 'product_id' => $products[$productCode]],
                ['min_quantity' => $minQuantity],
            );
        }
    }
}
