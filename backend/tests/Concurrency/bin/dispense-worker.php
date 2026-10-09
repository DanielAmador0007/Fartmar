<?php

/*
| Worker de las pruebas de concurrencia (tests/Concurrency).
|
| Cada ejecución es un PROCESO PHP independiente con SU PROPIA conexión a
| PostgreSQL: así la prueba ejercita bloqueos reales (SELECT ... FOR UPDATE),
| no una simulación dentro de una sola conexión.
|
| Uso (lo lanza ConcurrentDispenser, no a mano):
|   php tests/Concurrency/bin/dispense-worker.php '<json>'
|
| JSON de entrada:
|   mode: "create" (por defecto) | "authorize"
|   create:    user_id, patient_id, prescription_id, warehouse_id,
|              items: [[prescription_item_id, quantity], ...], idempotency_key
|   authorize: user_id (regente), dispensation_id
|   barrier (int, clave del advisory lock), unsafe (bool, opcional)
|
| Pasos:
|   1. Arranca Laravel y abre la conexión (lo lento ocurre ANTES de la barrera).
|   2. Espera en la barrera: pg_advisory_lock_shared(barrier). La prueba tiene
|      ese lock en modo exclusivo y lo suelta cuando TODOS los workers están
|      esperando, así arrancan prácticamente a la vez.
|   3. Llama a DispenseService::create o ::authorize (código real de producción).
|   4. Imprime UNA línea JSON con el resultado y termina con código 0.
|      Solo un error inesperado (no de negocio ni CHECK) termina con código 1.
|
| unsafe = true SOLO existe para demostrar que la prueba detecta el problema:
| quita todos los FOR UPDATE de este proceso (gramática SQL sin bloqueo) y
| agrega una pausa tras cada lectura de stocks para que todos lean el mismo
| saldo antes de escribir. Sin bloqueos, el CHECK (quantity >= 0) debe
| rechazar las salidas sobrantes. No toca el código de producción.
*/

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Domain\Dispensing\DispenseService;
use App\Domain\Exceptions\BusinessRuleException;
use App\Models\Dispensation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 3);
require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @param array<string, mixed> $result */
function respond(array $result, int $exitCode = 0): never
{
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
    exit($exitCode);
}

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! str_ends_with($database, '_test')) {
    respond(['outcome' => 'UNEXPECTED', 'message' => "El worker solo corre contra una BD *_test (recibió {$database})."], 2);
}

/** @var array{mode?: string, user_id: int, patient_id?: int, prescription_id?: int, warehouse_id?: int, items?: list<array{0: int, 1: int}>, idempotency_key?: string, dispensation_id?: int, barrier: int, unsafe?: bool} $job */
$job = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

/** @var Connection $connection */
$connection = DB::connection();
$connection->select('select 1');

if ($job['unsafe'] ?? false) {
    $connection->setQueryGrammar(new class($connection) extends PostgresGrammar
    {
        protected function compileLock(Builder $query, $value): string
        {
            return '';
        }
    });

    $connection->listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"stocks"')) {
            usleep(300_000);
        }
    });
}

$user = User::query()->findOrFail($job['user_id']);

// Lo que lee la BD se prepara ANTES de la barrera; después solo corre la acción.
if (($job['mode'] ?? 'create') === 'authorize') {
    $dispensation = Dispensation::query()->findOrFail($job['dispensation_id']);
    $action = fn (): array => ['dispensation_id' => app(DispenseService::class)->authorize($dispensation, $user)->id];
} else {
    $data = new DispenseData(
        $job['patient_id'],
        $job['prescription_id'],
        $job['warehouse_id'],
        array_map(fn (array $item) => new DispenseItemData($item[0], $item[1]), $job['items']),
    );
    $action = function () use ($user, $data, $job): array {
        $result = app(DispenseService::class)->create($user, $data, $job['idempotency_key']);

        return ['dispensation_id' => $result->dispensation->id, 'replayed' => $result->replayed];
    };
}

// Barrera: bloquea hasta que la prueba suelte el advisory lock exclusivo.
$connection->select('select pg_advisory_lock_shared(?)', [$job['barrier']]);
$connection->select('select pg_advisory_unlock_shared(?)', [$job['barrier']]);

try {
    respond(['outcome' => 'OK'] + $action());
} catch (BusinessRuleException $e) {
    respond(['outcome' => $e->errorCode()]);
} catch (QueryException $e) {
    // SQLSTATE 23514 = check_violation: el CHECK de la BD frenó la operación.
    if ($e->getCode() === '23514') {
        respond(['outcome' => 'CHECK_VIOLATION', 'message' => $e->getMessage()]);
    }

    respond(['outcome' => 'UNEXPECTED', 'sqlstate' => $e->getCode(), 'message' => $e->getMessage()], 1);
} catch (Throwable $e) {
    respond(['outcome' => 'UNEXPECTED', 'class' => $e::class, 'message' => $e->getMessage()], 1);
}
