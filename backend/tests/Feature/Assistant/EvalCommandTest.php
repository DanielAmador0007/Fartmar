<?php

/*
| php artisan assistant:eval: el conjunto completo pasa con el mock contra
| los datos semilla, y el comando falla (código 1) bajo el umbral.
*/

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('el conjunto de evaluación tiene al menos 12 casos con los campos esperados y cubre los casos del enunciado', function () {
    $suite = json_decode(file_get_contents(base_path('tests/Assistant/eval.json')), true, flags: JSON_THROW_ON_ERROR);

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
