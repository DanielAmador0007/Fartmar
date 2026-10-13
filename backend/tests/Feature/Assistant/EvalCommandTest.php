<?php

/*
| php artisan assistant:eval: el conjunto completo pasa con el mock contra
| los datos semilla, y el comando falla (código 1) bajo el umbral.
*/

use App\Domain\Inventory\AdjustmentService;
use App\Models\Stock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('el conjunto de evaluación tiene al menos 12 casos con los campos esperados y cubre los casos del enunciado', function () {
    $suite = json_decode(file_get_contents(base_path('database/assistant/eval.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(count($suite['cases']))->toBeGreaterThanOrEqual(12);
    foreach ($suite['cases'] as $case) {
        expect($case)->toHaveKeys(['id', 'question', 'expected_behavior'])
            ->and($case['expected_behavior'])->toBeIn(['answer', 'refuse', 'out_of_scope']);
    }

    $behaviors = array_count_values(array_column($suite['cases'], 'expected_behavior'));
    expect($behaviors['refuse'])->toBeGreaterThanOrEqual(3)
        ->and($behaviors['out_of_scope'])->toBeGreaterThanOrEqual(1)
        ->and(array_filter(array_column($suite['cases'], 'expected_tool')))->toContain(
            'get_expiring_lots', 'get_stock', 'get_below_minimum', 'get_expired_lots', 'get_transfers',
        );
});

it('el eval completo pasa al 100 % con el proveedor mock', function () {
    $exit = Artisan::call('assistant:eval', ['--provider' => 'mock', '--threshold' => 100]);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and($output)->toMatch('/(\d+)\/\1 aciertos \(100,0 %\)/')
        ->and($output)->not->toContain('FALLA');
});

it('falla con código 1 si los aciertos quedan bajo el umbral', function () {
    $file = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($file, json_encode(['cases' => [
        ['id' => 'X1', 'question' => '¿Qué bodegas existen?', 'expected_tool' => 'list_warehouses', 'expected_behavior' => 'answer'],
        ['id' => 'X2', 'question' => '¿Qué bodegas existen?', 'expected_tool' => 'get_stock', 'expected_behavior' => 'answer'],
    ]]));

    $exit = Artisan::call('assistant:eval', ['--file' => $file, '--threshold' => 75]);
    $output = Artisan::output();
    unlink($file);

    expect($exit)->toBe(1)
        ->and($output)->toContain('1/2 aciertos (50,0 %)')
        ->and($output)->toContain('no usó get_stock')
        ->and($output)->toContain('Por debajo del umbral');
});

it('un proveedor desconocido termina con error', function () {
    expect(Artisan::call('assistant:eval', ['--provider' => 'gpt-imaginario']))->toBe(1);
});

it('acepta alternativas de redacción y verifica los datos estructurados', function () {
    $file = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($file, json_encode(['cases' => [
        ['id' => 'A1', 'question' => '¿Qué lotes vencidos hay en Bodega Hospitalización?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_result_count' => 0, 'expected_answer_contains_any' => ['frase que no sale', 'No hay']],
        ['id' => 'A2', 'question' => '¿Qué lotes vencidos hay en Bodega Hospitalización?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_result_count' => 3],
        ['id' => 'A3', 'question' => '¿Qué lotes de amoxicilina vencen en los próximos 400 días?', 'expected_tool' => 'get_expiring_lots',
            'expected_tool_ok' => true, 'expected_behavior' => 'answer'],
        ['id' => 'A4', 'question' => '¿Qué lotes están vencidos?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_result_contains' => ['L-ACE-2401'], 'expected_answer_contains_any' => ['nada', 'ninguno']],
    ]]));

    Artisan::call('assistant:eval', ['--file' => $file, '--threshold' => 0]);
    $output = Artisan::output();
    unlink($file);

    expect($output)->toContain('1/4 aciertos')
        ->and($output)->toContain('result.count distinto de 3')
        ->and($output)->toContain('get_expiring_lots no se ejecutó')
        ->and($output)->toContain('no contiene ninguna de');
});

/** Deja en 0 las existencias de un lote en una bodega (como lo haría la demo al dispensarlo). */
function emptySeededLot(string $lotNumber, string $warehouseCode): void
{
    $stock = Stock::query()
        ->whereHas('lot', fn ($q) => $q->where('lot_number', $lotNumber))
        ->whereHas('warehouse', fn ($q) => $q->where('code', $warehouseCode))
        ->firstOrFail();

    app(AdjustmentService::class)->adjust(
        User::query()->where('email', 'regente@fartmar.test')->firstOrFail(),
        $stock->warehouse_id,
        $stock->lot_id,
        -$stock->quantity,
        'Simula la demo de la sustentación',
    );
}

it('M5: el eval sigue al 100 % después de que la demo agota lotes de la semilla, y mantiene el aviso', function () {
    // Demo A: 60 de acetaminofén en FAR-CEN agotan L-ACE-2402; morfina hasta agotar L-MOR-2401.
    emptySeededLot('L-ACE-2402', 'FAR-CEN');
    emptySeededLot('L-MOR-2401', 'FAR-CEN');
    emptySeededLot('L-OME-2401', 'FAR-CEN');

    $exit = Artisan::call('assistant:eval', ['--provider' => 'mock', '--threshold' => 100]);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('movimientos posteriores a la semilla')
        ->and($output)->toMatch('/(\d+)\/\1 aciertos \(100,0 %\)/')
        ->and($output)->not->toContain('FALLA');
});

it('M5: expected_lots_if_stocked exige el lote si tiene existencias y expected_result_not_contains rechaza lotes de más', function () {
    $file = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($file, json_encode(['cases' => [
        // BOD-HOS tiene L-ACE-2403 vigente: no sale en "vencidos", así que el caso debe fallar.
        ['id' => 'S1', 'question' => '¿Qué lotes vencidos hay en Bodega Hospitalización?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_lots_if_stocked' => [['lot' => 'L-ACE-2403', 'warehouse_code' => 'BOD-HOS']]],
        // L-ACE-2401 (vencido, con existencias en FAR-CEN) sí sale: acierto.
        ['id' => 'S2', 'question' => '¿Qué lotes están vencidos?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_lots_if_stocked' => [['lot' => 'L-ACE-2401', 'warehouse_code' => 'FAR-CEN']]],
        ['id' => 'S3', 'question' => '¿Qué lotes están vencidos?', 'expected_tool' => 'get_expired_lots',
            'expected_behavior' => 'answer', 'expected_result_not_contains' => ['L-ACE-2401']],
    ]]));

    Artisan::call('assistant:eval', ['--file' => $file, '--threshold' => 0]);
    $output = Artisan::output();
    unlink($file);

    expect($output)->toContain('1/3 aciertos')
        ->and($output)->toContain('result sin L-ACE-2403 en BOD-HOS (tiene existencias)')
        ->and($output)->toContain('result con "L-ACE-2401"');
});

it('M5: un lote sin existencias ya no se exige (ni puede aparecer en las filas)', function () {
    emptySeededLot('L-ACE-2402', 'FAR-CEN');
    $file = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($file, json_encode(['cases' => [
        ['id' => 'E1', 'question' => '¿Qué lotes de acetaminofén vencen en los próximos 60 días en la farmacia central?',
            'expected_tool' => 'get_expiring_lots', 'expected_tool_ok' => true, 'expected_behavior' => 'answer',
            'expected_lots_if_stocked' => [['lot' => 'L-ACE-2402', 'warehouse_code' => 'FAR-CEN']],
            'expected_answer_contains_any' => ['No encontré lotes']],
    ]]));

    Artisan::call('assistant:eval', ['--file' => $file, '--threshold' => 100]);
    $output = Artisan::output();
    unlink($file);

    expect($output)->toContain('1/1 aciertos');
});

it('avisa si la semilla fue modificada', function () {
    $s = TransferScenario::make();
    app(AdjustmentService::class)->adjust($s->regente, $s->origin->id, $s->lots[0]->id, -1, 'Conteo físico');

    Artisan::call('assistant:eval', ['--threshold' => 0]);

    expect(Artisan::output())->toContain('movimientos posteriores a la semilla');
});
