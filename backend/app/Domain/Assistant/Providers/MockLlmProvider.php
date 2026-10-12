<?php

namespace App\Domain\Assistant\Providers;

use App\Domain\Assistant\AssistantMessages;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\LlmProvider;
use App\Domain\Assistant\LlmResponse;
use App\Domain\Assistant\Text;
use App\Domain\Assistant\ToolCall;

/**
 * "Modelo" simulado: determinista, sin red ni llaves (LLM_PROVIDER=mock, por
 * defecto). Se comporta como un modelo con function calling para que el
 * bucle real de AssistantService, la validación y la autorización de las
 * herramientas se ejerciten igual que con un modelo de verdad:
 *
 *   1ª llamada: reconoce la intención con reglas (regex sobre el texto sin
 *      tildes). Si menciona un medicamento ("lotes DE acetaminofén"), pide
 *      search_products; si no, pide directamente la herramienta.
 *   2ª llamada: con el product_id encontrado, pide la herramienta final.
 *   3ª llamada: redacta la respuesta con los datos del <tool_result>.
 *
 * Las bodegas las reconoce leyendo el catálogo del system prompt, como haría
 * un modelo real. Nunca copia el texto libre de las observaciones (notes).
 * Sin intención reconocida responde la respuesta estándar de fuera de alcance.
 */
final class MockLlmProvider implements LlmProvider
{
    /**
     * Intenciones en orden de prioridad: herramienta, patrón y si admite
     * filtro por producto.
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    private const INTENTS = [
        ['list_warehouses', '/\b(que|cuales|lista|listado|todas las)\b.*\bbodegas\b|\bbodegas (hay|existen)\b/', false],
        ['get_transfers', '/traslad/', false],
        ['get_below_minimum', '/(bajo|debajo)( de| del)?( el)? (stock )?minimo|stock minimo|reponer|reabastec/', false],
        ['get_expired_lots', '/\b(vencid[oa]s?|caducad[oa]s?|ya vencieron)\b/', true],
        ['get_expiring_lots', '/venc|caduc/', true],
        ['get_stock', '/stock|existencia|inventario|cuant[oa]s?|unidades|disponib|\bhay\b/', true],
    ];

    /** @var array<string, string> patrón => estado del traslado */
    private const TRANSFER_STATUS = [
        '/transito|despachad/' => 'EN_TRANSITO',
        '/solicitad|pendientes? de aprob|por aprobar/' => 'SOLICITADO',
        '/aprobad/' => 'APROBADO',
        '/parcial/' => 'RECIBIDO_PARCIAL',
        '/recibid/' => 'RECIBIDO',
        '/anulad/' => 'ANULADO',
        '/borrador/' => 'BORRADOR',
    ];

    private const STATUS_LABELS = [
        'BORRADOR' => 'borrador',
        'SOLICITADO' => 'solicitado',
        'APROBADO' => 'aprobado',
        'EN_TRANSITO' => 'en tránsito',
        'RECIBIDO' => 'recibido',
        'RECIBIDO_PARCIAL' => 'recibido parcial',
        'ANULADO' => 'anulado',
    ];

    /** Palabras que siguen a "de" y no son nombres de medicamento. */
    private const NOT_PRODUCTS = [
        'stock', 'lote', 'lotes', 'inventario', 'existencias', 'unidades', 'producto', 'productos',
        'medicamento', 'medicamentos', 'bodega', 'bodegas', 'farmacia', 'dias', 'meses', 'semana',
        'semanas', 'vencimiento', 'todos', 'todas', 'cada', 'hoy', 'manana', 'aqui',
    ];

    /** Palabras genéricas de los nombres de bodega (no las distinguen). */
    private const GENERIC_WAREHOUSE_WORDS = ['farmacia', 'bodega', 'de', 'del', 'la', 'el', 'los', 'las'];

    public function name(): string
    {
        return 'mock';
    }

    public function chat(array $messages, array $tools): LlmResponse
    {
        $question = Text::normalize($this->lastContent($messages, 'user'));
        $intent = $this->intent($question);

        if ($intent === null) {
            return LlmResponse::text(AssistantMessages::OUT_OF_SCOPE);
        }

        [$tool, $usesProduct] = $intent;
        $results = $this->toolResults($messages);
        [$warehouseCode, $warehouseWords] = $this->warehouse($question, $this->lastContent($messages, 'system'));

        $args = [];
        if ($warehouseCode !== null && $tool !== 'list_warehouses') {
            $args['warehouse_code'] = $warehouseCode;
        }

        if ($usesProduct && ($term = $this->productTerm($question, $warehouseWords)) !== null) {
            if (! isset($results['search_products'])) {
                return $this->call('search_products', ['query' => $term]);
            }

            $found = $results['search_products']['data']['rows'][0]['id'] ?? null;
            if ($found === null) {
                return LlmResponse::text("No encontré el producto «{$term}» en el catálogo. Revise el nombre o use el código del producto.");
            }
            $args['product_id'] = $found;
        }

        if (! isset($results[$tool])) {
            return $this->call($tool, $this->argsFor($tool, $question, $args));
        }

        return LlmResponse::text($this->answer($tool, $results[$tool]));
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    private function intent(string $question): ?array
    {
        foreach (self::INTENTS as [$tool, $pattern, $usesProduct]) {
            if (preg_match($pattern, $question) === 1) {
                return [$tool, $usesProduct];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function argsFor(string $tool, string $question, array $args): array
    {
        return match ($tool) {
            'list_warehouses' => [],
            'get_below_minimum' => array_intersect_key($args, ['warehouse_code' => true]),
            'get_transfers' => array_filter([
                'status' => $this->transferStatus($question),
                'warehouse_code' => $args['warehouse_code'] ?? null,
            ]),
            'get_expiring_lots' => ['days' => $this->days($question), ...$args],
            default => $args,
        };
    }

    /**
     * Días de la ventana: "60 días", "2 meses", "una semana"; si no dice,
     * la ventana de alertas (ALERT_EXPIRY_DAYS). No se recorta: si piden
     * 400 días, la herramienta lo rechaza (validación del servidor).
     */
    private function days(string $question): int
    {
        return match (true) {
            preg_match('/(\d{1,5}) ?dias?\b/', $question, $m) === 1 => (int) $m[1],
            preg_match('/(\d{1,3}) ?mes(es)?\b/', $question, $m) === 1 => (int) $m[1] * 30,
            preg_match('/\b(un|proximo) mes\b/', $question) === 1 => 30,
            preg_match('/semana/', $question) === 1 => 7,
            default => (int) config('fartmar.alert_expiry_days', 90),
        };
    }

    private function transferStatus(string $question): ?string
    {
        foreach (self::TRANSFER_STATUS as $pattern => $status) {
            if (preg_match($pattern, $question) === 1) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Bodega mencionada, reconocida con el catálogo "- CODIGO: Nombre" del
     * system prompt: por su código o por una palabra distintiva del nombre
     * ("central", "urgencias").
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function warehouse(string $question, string $systemPrompt): array
    {
        preg_match_all('/^\s*- ([A-Z0-9-]+): (.+)$/m', $systemPrompt, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $code, $name]) {
            $words = array_values(array_diff(explode(' ', Text::normalize($name)), self::GENERIC_WAREHOUSE_WORDS));
            $words[] = Text::normalize($code);

            foreach ($words as $word) {
                if ($word !== '' && preg_match('/\b'.preg_quote($word, '/').'\b/', $question) === 1) {
                    return [$code, $words];
                }
            }
        }

        return [null, []];
    }

    /**
     * Medicamento mencionado: la primera palabra de 4+ letras después de
     * "de"/"del" que no sea genérica ni de la bodega
     * ("lotes de acetaminofén" -> "acetaminofen").
     *
     * @param  list<string>  $warehouseWords
     */
    private function productTerm(string $question, array $warehouseWords): ?string
    {
        preg_match_all('/\bdel? ([a-z0-9]{4,})/', $question, $matches);

        foreach ($matches[1] as $word) {
            if (! in_array($word, self::NOT_PRODUCTS, true) && ! in_array($word, $warehouseWords, true)) {
                return $word;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function call(string $tool, array $args): LlmResponse
    {
        return LlmResponse::toolCalls(new ToolCall('mock_'.$tool, $tool, $args));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function lastContent(array $messages, string $role): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === $role) {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Resultados de herramientas ya ejecutadas, por nombre.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, array<string, mixed>>
     */
    private function toolResults(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $payload = AssistantService::unwrapToolResult((string) ($message['content'] ?? ''));
                if ($payload !== null) {
                    $results[(string) ($message['name'] ?? '')] = $payload;
                }
            }
        }

        return $results;
    }

    /**
     * Redacta la respuesta a partir del resultado (nunca inventa: si no hay
     * filas, lo dice).
     *
     * @param  array<string, mixed>  $payload
     */
    private function answer(string $tool, array $payload): string
    {
        if (($payload['ok'] ?? false) !== true) {
            return 'No pude completar la consulta: '.($payload['error']['message'] ?? 'error desconocido.');
        }

        $data = $payload['data'];
        $rows = $data['rows'] ?? [];
        $filters = $data['filters'] ?? [];
        $product = isset($filters['product']['name']) ? ' de '.$filters['product']['name'] : '';
        $where = isset($filters['warehouse']['name']) ? ' en '.$filters['warehouse']['name'] : '';
        $more = ($data['truncated'] ?? false) ? "\n(Se muestran solo las primeras filas; filtre por bodega o producto para ver el resto.)" : '';

        $text = match ($tool) {
            'get_expiring_lots' => $rows === []
                ? "No encontré lotes{$product} con existencias que venzan en los próximos {$filters['days']} días{$where}."
                : "Lotes{$product} que vencen en los próximos {$filters['days']} días{$where}:\n".$this->lines($rows, fn (array $r) => sprintf(
                    '- %s (%s) en %s: %d unidades, %s.',
                    $r['lot_number'], $r['product']['name'], $r['warehouse']['name'], $r['quantity'],
                    $r['days_to_expire'] === 0 ? 'vence hoy' : "vence el {$r['expires_at']} (en {$r['days_to_expire']} días)",
                )),
            'get_expired_lots' => $rows === []
                ? "No hay lotes vencidos con existencias{$product}{$where}."
                : "Lotes vencidos con existencias{$product}{$where} (no se pueden dispensar ni trasladar; deben darse de baja con un ajuste):\n"
                    .$this->lines($rows, fn (array $r) => sprintf(
                        '- %s (%s) en %s: %d unidades, venció el %s.',
                        $r['lot_number'], $r['product']['name'], $r['warehouse']['name'], $r['quantity'], $r['expires_at'],
                    )),
            'get_stock' => $rows === []
                ? "No hay existencias{$product}{$where}."
                : "Existencias{$product}{$where}: {$data['total_quantity']} unidades en total, {$data['available_quantity']} disponibles (sin vencer).\n"
                    .$this->lines($rows, fn (array $r) => sprintf(
                        '- %s · %s · lote %s: %d unidades, vence el %s%s.',
                        $r['warehouse']['name'], $r['product']['name'], $r['lot_number'], $r['quantity'], $r['expires_at'],
                        $r['is_expired'] ? ' (VENCIDO)' : '',
                    )),
            'get_below_minimum' => $rows === []
                ? "Ningún producto está por debajo del stock mínimo{$where}."
                : "Productos por debajo del stock mínimo{$where}:\n".$this->lines($rows, fn (array $r) => sprintf(
                    '- %s en %s: disponible %d de un mínimo de %d (faltan %d).',
                    $r['product']['name'], $r['warehouse']['name'], $r['available'], $r['min_quantity'], $r['shortfall'],
                )),
            'get_transfers' => $this->transfersAnswer($rows, $filters, $where),
            'list_warehouses' => "Bodegas registradas:\n".$this->lines($rows, fn (array $r) => "- {$r['code']}: {$r['name']}"),
            default => AssistantMessages::OUT_OF_SCOPE,
        };

        return $text.$more;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    private function transfersAnswer(array $rows, array $filters, string $where): string
    {
        $status = isset($filters['status']) ? ' en estado '.(self::STATUS_LABELS[$filters['status']] ?? $filters['status']) : '';

        if ($rows === []) {
            return "No encontré traslados{$status}{$where}.";
        }

        $text = "Traslados{$status}{$where}:\n".$this->lines($rows, fn (array $r) => sprintf(
            '- #%d (%s): %s → %s; %s; solicitado el %s%s.',
            $r['id'],
            self::STATUS_LABELS[$r['status']] ?? $r['status'],
            $r['origin']['name'],
            $r['destination']['name'],
            implode(', ', array_map(fn (array $i) => "{$i['product']['name']} x {$i['quantity_requested']}", $r['items'])),
            $r['requested_at'] ?? 'sin fecha',
            isset($r['dispatched_at']) ? ", despachado el {$r['dispatched_at']}" : '',
        ));

        // Las observaciones son texto de usuarios (untrusted): no se repiten.
        if (array_filter(array_column($rows, 'notes')) !== []) {
            $text .= "\nAlgunos traslados tienen observaciones escritas por usuarios; no las repito porque son texto no verificado. Puede verlas en el detalle del traslado.";
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function lines(array $rows, \Closure $format): string
    {
        return implode("\n", array_map($format, $rows));
    }
}
