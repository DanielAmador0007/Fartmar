<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sondas para el orquestador de contenedores.
 *
 * - /health (liveness): el proceso PHP responde. NO toca la base de datos,
 *   para que una caída de Postgres no provoque reinicios en cascada de la API.
 * - /ready (readiness): la BD responde y no hay migraciones pendientes.
 *
 * Ninguna de las dos expone detalles internos (mensajes de error, hosts, versiones).
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReachable() ? 'ok' : 'fail',
            'migrations' => 'unknown',
        ];

        if ($checks['database'] === 'ok') {
            $checks['migrations'] = $this->migrationsAreApplied($migrator) ? 'ok' : 'pending';
        }

        $ready = $checks['database'] === 'ok' && $checks['migrations'] === 'ok';

        return response()->json(
            ['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable $e) {
            // Solo la clase de la excepción: el mensaje puede incluir host/usuario.
            Log::warning('readiness: base de datos no disponible', ['exception' => $e::class]);

            return false;
        }
    }

    private function migrationsAreApplied(Migrator $migrator): bool
    {
        try {
            if (! $migrator->repositoryExists()) {
                return false;
            }

            $paths = array_merge([database_path('migrations')], $migrator->paths());
            $files = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->getRepository()->getRan();

            return array_diff($files, $ran) === [];
        } catch (Throwable $e) {
            Log::warning('readiness: no se pudo verificar migraciones', ['exception' => $e::class]);

            return false;
        }
    }
}
