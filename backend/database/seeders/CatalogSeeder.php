<?php

namespace Database\Seeders;

use App\Models\Lot;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Bodegas, medicamentos y lotes. Los vencimientos son RELATIVOS a hoy (zona de
 * negocio) para que la demo siempre tenga:
 *  - un lote vencido (L-ACE-2401),
 *  - lotes que vencen en < 30 días (L-ACE-2402, L-OME-2401),
 *  - lotes en la ventana de alerta de 30–90 días,
 *  - lotes lejanos.
 * Al re-ejecutar el seeder las fechas se recalculan desde el día actual.
 */
class CatalogSeeder extends Seeder
{
    public const WAREHOUSES = [
        'FAR-CEN' => 'Farmacia Central',
        'FAR-URG' => 'Farmacia Urgencias',
        'BOD-HOS' => 'Bodega Hospitalización',
    ];

    /**
     * código => [nombre, presentación, unidad, control especial, [lote => días para vencer]]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: array<string, int>}>
     */
    public const PRODUCTS = [
        'ACE500' => ['Acetaminofén 500 mg', 'Tableta 500 mg', 'tableta', false, [
            'L-ACE-2401' => -10,  // VENCIDO: nunca se dispensa ni se traslada
            'L-ACE-2402' => 20,   // vence en < 30 días
            'L-ACE-2403' => 300,
        ]],
        'IBU400' => ['Ibuprofeno 400 mg', 'Tableta 400 mg', 'tableta', false, [
            'L-IBU-2401' => 45,
            'L-IBU-2402' => 400,
        ]],
        'AMX500' => ['Amoxicilina 500 mg', 'Cápsula 500 mg', 'cápsula', false, [
            'L-AMX-2401' => 60,
            'L-AMX-2402' => 200,
        ]],
        'LOS50' => ['Losartán 50 mg', 'Tableta 50 mg', 'tableta', false, [
            'L-LOS-2401' => 85,
            'L-LOS-2402' => 500,
        ]],
        'OME20' => ['Omeprazol 20 mg', 'Cápsula 20 mg', 'cápsula', false, [
            'L-OME-2401' => 15,   // vence en < 30 días
            'L-OME-2402' => 365,
        ]],
        'MOR10' => ['Morfina 10 mg/ml', 'Ampolla 10 mg/ml x 1 ml', 'ampolla', true, [
            'L-MOR-2401' => 70,
            'L-MOR-2402' => 250,
        ]],
    ];

    public function run(): void
    {
        foreach (self::WAREHOUSES as $code => $name) {
            Warehouse::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $today = CarbonImmutable::now(config('fartmar.business_timezone'))->startOfDay();

        foreach (self::PRODUCTS as $code => [$name, $presentation, $unit, $isControlled, $lots]) {
            $product = Product::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'presentation' => $presentation,
                'unit' => $unit,
                'is_controlled' => $isControlled,
            ]);

            foreach ($lots as $lotNumber => $daysToExpire) {
                Lot::query()->updateOrCreate(
                    ['product_id' => $product->id, 'lot_number' => $lotNumber],
                    ['expires_at' => $today->addDays($daysToExpire)->toDateString()],
                );
            }
        }
    }
}
