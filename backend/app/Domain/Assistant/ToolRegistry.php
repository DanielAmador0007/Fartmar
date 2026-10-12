<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Tools\AssistantTool;
use App\Domain\Assistant\Tools\GetBelowMinimumTool;
use App\Domain\Assistant\Tools\GetExpiredLotsTool;
use App\Domain\Assistant\Tools\GetExpiringLotsTool;
use App\Domain\Assistant\Tools\GetStockTool;
use App\Domain\Assistant\Tools\GetTransfersTool;
use App\Domain\Assistant\Tools\ListWarehousesTool;
use App\Domain\Assistant\Tools\SearchProductsTool;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Lista blanca de herramientas del asistente y único punto que las ejecuta.
 *
 * Para cada pedido del modelo, en este orden:
 *   1. ¿El nombre está en la lista blanca?        no -> HERRAMIENTA_DESCONOCIDA
 *   2. ¿El usuario puede leer eso? (Policies)     no -> NO_AUTORIZADO
 *   3. ¿Solo trae argumentos conocidos y válidos? no -> ARGUMENTOS_INVALIDOS
 *   4. Ejecuta la herramienta (solo lectura).
 *
 * Los errores se devuelven como ToolResult (el modelo los lee y responde),
 * nunca se ejecuta nada fuera de la lista. No hay herramientas de escritura
 * ni de SQL.
 */
final class ToolRegistry
{
    /** Mensajes de validación en español para el modelo (y el usuario). */
    private const MESSAGES = [
        'required' => 'El argumento :attribute es obligatorio.',
        'integer' => 'El argumento :attribute debe ser un número entero.',
        'string' => 'El argumento :attribute debe ser texto.',
        'between' => 'El argumento :attribute debe estar entre :min y :max.',
        'min' => 'El argumento :attribute es demasiado pequeño o corto (mínimo :min).',
        'max' => 'El argumento :attribute es demasiado grande o largo (máximo :max).',
        'exists' => 'El valor de :attribute no existe; use search_products o list_warehouses para obtener uno válido.',
        'enum' => 'El valor de :attribute no es válido.',
    ];

    /** @var array<string, AssistantTool> */
    private array $tools = [];

    public function __construct(
        SearchProductsTool $searchProducts,
        ListWarehousesTool $listWarehouses,
        GetStockTool $getStock,
        GetExpiringLotsTool $getExpiringLots,
        GetExpiredLotsTool $getExpiredLots,
        GetBelowMinimumTool $getBelowMinimum,
        GetTransfersTool $getTransfers,
    ) {
        $tools = [$searchProducts, $listWarehouses, $getStock, $getExpiringLots, $getExpiredLots, $getBelowMinimum, $getTransfers];
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Definiciones neutrales (nombre, descripción, JSON Schema); cada
     * proveedor las traduce a su formato.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (AssistantTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $this->tools));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function execute(User $user, ToolCall $call): ToolResult
    {
        $tool = $this->tools[$call->name] ?? null;
        if ($tool === null) {
            return ToolResult::failure(
                'HERRAMIENTA_DESCONOCIDA',
                'Esa herramienta no existe. Solo están permitidas: '.implode(', ', $this->names()).'.',
            );
        }

        if (! $tool->authorize($user)) {
            return ToolResult::failure('NO_AUTORIZADO', 'Su rol no tiene permiso para consultar esta información.');
        }

        $args = $tool->normalize($call->arguments);

        $allowed = array_keys($tool->rules());
        if (array_diff(array_keys($args), $allowed) !== []) {
            return ToolResult::failure(
                'ARGUMENTOS_INVALIDOS',
                $allowed === []
                    ? "La herramienta {$tool->name()} no recibe argumentos."
                    : "La herramienta {$tool->name()} solo acepta: ".implode(', ', $allowed).'.',
            );
        }

        $validator = Validator::make($args, $tool->rules(), self::MESSAGES);
        if ($validator->fails()) {
            return ToolResult::failure('ARGUMENTOS_INVALIDOS', implode(' ', $validator->errors()->all()));
        }

        return ToolResult::success($tool->run($validator->validated()));
    }
}
