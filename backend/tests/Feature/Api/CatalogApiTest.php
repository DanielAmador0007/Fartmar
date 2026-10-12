<?php

/*
| GET /api/v1/warehouses y GET /api/v1/products: catálogos de solo lectura
| para todos los roles activos (los permisos por rol están en
| AuthorizationMatrixTest).
*/

use App\Enums\Role;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lista las bodegas ordenadas por código con la forma {id, code, name}', function () {
    $b = Warehouse::factory()->create(['code' => 'BOD-B', 'name' => 'Bodega B']);
    $a = Warehouse::factory()->create(['code' => 'BOD-A', 'name' => 'Bodega A']);
    Sanctum::actingAs(User::factory()->medico()->create());

    $response = $this->getJson('/api/v1/warehouses')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 100);

    expect($response->json('data'))->toBe([
        ['id' => $a->id, 'code' => 'BOD-A', 'name' => 'Bodega A'],
        ['id' => $b->id, 'code' => 'BOD-B', 'name' => 'Bodega B'],
    ]);
});

it('pagina las bodegas y valida per_page', function () {
    Warehouse::factory()->count(3)->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/warehouses?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/v1/warehouses?per_page=101')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
});

it('lista productos ordenados por nombre con presentación, control especial y mínimos por bodega', function () {
    $warehouse = Warehouse::factory()->create();
    $controlled = Product::factory()->controlled()->create([
        'code' => 'MED-900', 'name' => 'Morfina 10 mg', 'presentation' => 'Ampolla 1 ml', 'unit' => 'ampolla',
    ]);
    $plain = Product::factory()->create(['code' => 'MED-100', 'name' => 'Acetaminofén 500 mg']);
    StockMinimum::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $controlled->id, 'min_quantity' => 15]);
    Sanctum::actingAs(User::factory()->medico()->create());

    $response = $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonPath('data.0.id', $plain->id)
        ->assertJsonPath('data.0.is_controlled', false)
        ->assertJsonPath('data.0.stock_minimums', []);

    expect($response->json('data.1'))->toBe([
        'id' => $controlled->id,
        'code' => 'MED-900',
        'name' => 'Morfina 10 mg',
        'presentation' => 'Ampolla 1 ml',
        'unit' => 'ampolla',
        'is_controlled' => true,
        'stock_minimums' => [['warehouse_id' => $warehouse->id, 'min_quantity' => 15]],
    ]);
});

it('busca productos por nombre o código sin distinguir mayúsculas', function () {
    $ace = Product::factory()->create(['code' => 'MED-100', 'name' => 'Acetaminofén 500 mg']);
    $ibu = Product::factory()->create(['code' => 'ANA-200', 'name' => 'Ibuprofeno 400 mg']);
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->getJson('/api/v1/products?q=acetamin')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ace->id);

    $this->getJson('/api/v1/products?q=ana-2')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ibu->id);

    // Vacío o solo espacios = sin filtro.
    $this->getJson('/api/v1/products?q=%20%20')->assertOk()->assertJsonCount(2, 'data');
});

it('la búsqueda de productos no distingue tildes (en el término ni en el nombre)', function () {
    $ace = Product::factory()->create(['code' => 'MED-100', 'name' => 'Acetaminofén 500 mg']);
    $los = Product::factory()->create(['code' => 'MED-200', 'name' => 'Losartan 50 mg']);
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->getJson('/api/v1/products?q=acetaminofen')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ace->id);
    $this->getJson('/api/v1/products?q=ACETAMINOFÉN')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ace->id);
    $this->getJson('/api/v1/products?q='.urlencode('losartán'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $los->id);
});

it('los comodines % y _ de la búsqueda se tratan como texto literal', function () {
    Product::factory()->create(['name' => 'Acetaminofén 500 mg']);
    $literal = Product::factory()->create(['name' => 'Solución 0,9% salina']);
    Sanctum::actingAs(User::factory()->auditor()->create());

    $this->getJson('/api/v1/products?q='.urlencode('%'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);

    $this->getJson('/api/v1/products?q=_')->assertOk()->assertJsonCount(0, 'data');
});

it('valida los filtros de productos', function () {
    Sanctum::actingAs(User::factory()->regente()->create());

    $this->getJson('/api/v1/products?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
    $this->getJson('/api/v1/products?per_page=0')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
});

it('un usuario desactivado no puede leer los catálogos', function (string $uri) {
    Sanctum::actingAs(User::factory()->role(Role::AuxiliarFarmacia)->create(['is_active' => false]));

    $this->getJson($uri)->assertForbidden();
})->with(['/api/v1/warehouses', '/api/v1/products']);
