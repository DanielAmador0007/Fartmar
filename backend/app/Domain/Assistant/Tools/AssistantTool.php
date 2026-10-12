<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Inventory\ExpiringLotAlert;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Herramienta de SOLO LECTURA que el modelo puede pedir (function calling).
 *
 * Cada herramienta declara:
 * - parameters(): JSON Schema que se le muestra al modelo.
 * - rules(): reglas de Laravel con las que el SERVIDOR valida los argumentos
 *   (el JSON Schema solo orienta al modelo; no se confía en que lo respete).
 * - authorize(): reutiliza las Policies existentes ($user->can(...)).
 * - run(): consulta servicios de dominio existentes y devuelve datos planos.
 *
 * Ninguna herramienta devuelve pacientes, prescripciones ni dispensaciones,
 * ni nombres de usuarios internos (minimización).
 */
abstract class AssistantTool
{
    /** Máximo de filas que una herramienta devuelve al modelo. */
    public const MAX_ROWS = 50;

    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * JSON Schema (type: object) de los argumentos.
     *
     * @return array<string, mixed>
     */
    abstract public function parameters(): array;

    /**
     * Reglas de validación de Laravel. Sus claves son los ÚNICOS argumentos
     * permitidos; cualquier otro se rechaza.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    abstract public function authorize(User $user): bool;

    /**
     * @param  array<string, mixed>  $args  ya validados
     * @return array<string, mixed>
     */
    abstract public function run(array $args): array;

    /**
     * Versión de los datos que se envía al MODELO. Por defecto, la misma que
     * recibe la UI; una herramienta con texto libre de usuarios la recorta.
     *
     * @param  array<string, mixed>  $data  resultado de run()
     * @return array<string, mixed>
     */
    public function forModel(array $data): array
    {
        return $data;
    }

    /**
     * Ajustes de forma antes de validar (los modelos a veces mandan el código
     * de bodega en minúsculas o con espacios).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function normalize(array $args): array
    {
        foreach (['warehouse_code', 'status'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $args[$key] = strtoupper(trim($args[$key]));
            }
        }

        return $args;
    }

    /**
     * Esquema común de los filtros opcionales.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function warehouseCodeSchema(): array
    {
        return ['warehouse_code' => [
            'type' => 'string',
            'description' => 'Código de la bodega (por ejemplo FAR-CEN). Omítalo para todas las bodegas.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function productIdSchema(): array
    {
        return ['product_id' => [
            'type' => 'integer',
            'description' => 'Id del producto obtenido con search_products. Omítalo para todos los productos.',
        ]];
    }

    protected function findWarehouse(mixed $code): ?Warehouse
    {
        return is_string($code) ? Warehouse::query()->where('code', $code)->first() : null;
    }

    protected function findProduct(mixed $id): ?Product
    {
        return $id === null ? null : Product::query()->find((int) $id);
    }

    /**
     * @return array{code: string, name: string}|null
     */
    protected static function warehouseRef(?Warehouse $warehouse): ?array
    {
        return $warehouse === null ? null : ['code' => $warehouse->code, 'name' => $warehouse->name];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    protected static function productRef(?Product $product): ?array
    {
        return $product === null ? null : ['id' => $product->id, 'code' => $product->code, 'name' => $product->name];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function lotRow(ExpiringLotAlert $alert): array
    {
        return [
            'warehouse' => self::warehouseRef($alert->stock->warehouse),
            'product' => self::productRef($alert->stock->product),
            'lot_number' => $alert->stock->lot->lot_number,
            'expires_at' => $alert->stock->lot->expires_at->toDateString(),
            'days_to_expire' => $alert->daysToExpire,
            'quantity' => $alert->stock->quantity,
        ];
    }
}
