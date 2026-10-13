<?php

/*
| php artisan assistant:eval: el conjunto completo pasa con el mock contra
| los datos semilla, y el comando falla (código 1) bajo el umbral.
*/

use App\Domain\Inventory\AdjustmentService;
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

it('avisa si la semilla fue modificada', function () {
    $s = TransferScenario::make();
    app(AdjustmentService::class)->adjust($s->regente, $s->origin->id, $s->lots[0]->id, -1, 'Conteo físico');

    Artisan::call('assistant:eval', ['--threshold' => 0]);

    expect(Artisan::output())->toContain('movimientos posteriores a la semilla');
});
