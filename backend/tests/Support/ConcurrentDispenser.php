<?php

namespace Tests\Support;

use App\Models\KardexMovement;
use App\Models\Stock;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lanza N procesos PHP reales (tests/Concurrency/bin/dispense-worker.php),
 * cada uno con su propia conexión a PostgreSQL, y los suelta A LA VEZ con una
 * barrera basada en un advisory lock:
 *
 *   1. La prueba toma pg_advisory_lock(barrier) (exclusivo).
 *   2. Arranca los N workers; cada uno arranca Laravel, conecta y se queda
 *      esperando en pg_advisory_lock_shared(barrier).
 *   3. La prueba consulta pg_locks hasta ver N esperas no concedidas, es
 *      decir, todos listos en la línea de salida.
 *   4. Suelta el lock: los N workers continúan al mismo tiempo.
 *
 * Los datos del escenario deben estar CONFIRMADOS en la BD (sin transacción
 * envolvente): por eso estas pruebas no usan RefreshDatabase.
 */
final class ConcurrentDispenser
{
    private const WORKER = 'tests/Concurrency/bin/dispense-worker.php';

    private const READY_TIMEOUT_SECONDS = 30;

    /**
     * @param  list<array<string, mixed>>  $jobs  ver el encabezado del worker
     * @return list<array<string, mixed>> un resultado por job, en el mismo orden
     */
    public static function run(array $jobs): array
    {
        $barrier = random_int(1_000_000, 2_000_000_000);
        DB::select('select pg_advisory_lock(?)', [$barrier]);

        /** @var list<InvokedProcess> $processes */
        $processes = [];

        try {
            foreach ($jobs as $job) {
                $processes[] = Process::path(base_path())
                    ->env(self::workerEnv())
                    ->timeout(120)
                    ->start(['php', self::WORKER, json_encode($job + ['barrier' => $barrier], JSON_THROW_ON_ERROR)]);
            }

            self::waitUntilAllWaiting($barrier, $processes);
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$barrier]);
        }

        return array_map(self::result(...), $processes);
    }

    /**
     * RN-06: para cada existencia, los movimientos de kardex (en orden de id)
     * deben encadenar balance_after = saldo anterior ± cantidad partiendo de 0,
     * y el último saldo debe ser igual a stocks.quantity (nunca negativo).
     *
     * @return list<string> descripción de cada inconsistencia (vacío = OK)
     */
    public static function kardexInconsistencies(): array
    {
        $problems = [];

        foreach (Stock::query()->orderBy('id')->get() as $stock) {
            $balance = 0;
            $movements = KardexMovement::query()
                ->where('warehouse_id', $stock->warehouse_id)
                ->where('lot_id', $stock->lot_id)
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $balance += $movement->direction * $movement->quantity;
                if ($movement->balance_after !== $balance) {
                    $problems[] = "Movimiento {$movement->id}: balance_after {$movement->balance_after}, esperado {$balance}.";
                }
            }

            if ($stock->quantity !== $balance) {
                $problems[] = "Existencia {$stock->id}: quantity {$stock->quantity}, suma del kardex {$balance}.";
            }

            if ($stock->quantity < 0) {
                $problems[] = "Existencia {$stock->id}: stock negativo ({$stock->quantity}).";
            }
        }

        return $problems;
    }

    /**
     * Deadlocks que PostgreSQL ha detectado en la BD de pruebas (acumulado).
     * Los workers ya terminaron, así que sus estadísticas están publicadas;
     * pg_stat_clear_snapshot() evita leer una foto vieja.
     */
    public static function deadlocksDetected(): int
    {
        DB::select('select pg_stat_clear_snapshot()');

        return (int) DB::scalar('select deadlocks from pg_stat_database where datname = current_database()');
    }

    /**
     * El worker usa la misma BD de pruebas que este proceso (nunca la de
     * desarrollo: el worker además aborta si la BD no termina en _test).
     *
     * @return array<string, string>
     */
    private static function workerEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_URL' => '',
            'LOG_CHANNEL' => 'null',
            'CACHE_STORE' => 'array',
            'LLM_PROVIDER' => 'mock',
        ];
    }

    /**
     * @param  list<InvokedProcess>  $processes
     */
    private static function waitUntilAllWaiting(int $barrier, array $processes): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;

        while (true) {
            $waiting = (int) DB::scalar(
                "select count(*) from pg_locks
                 where locktype = 'advisory' and classid = 0 and objid = ? and objsubid = 1 and not granted",
                [$barrier],
            );

            if ($waiting === count($processes)) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->running()) {
                    $finished = $process->wait();
                    throw new RuntimeException('Un worker terminó antes de la barrera: '.$finished->output().$finished->errorOutput());
                }
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("Solo {$waiting} de ".count($processes).' workers llegaron a la barrera.');
            }

            usleep(20_000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(InvokedProcess $process): array
    {
        $finished = $process->wait();
        $lines = array_values(array_filter(explode("\n", trim($finished->output()))));
        $last = end($lines);

        $decoded = $last === false ? null : json_decode($last, true);
        if (! is_array($decoded)) {
            return ['outcome' => 'UNEXPECTED', 'message' => $finished->output().$finished->errorOutput()];
        }

        return $decoded;
    }
}
