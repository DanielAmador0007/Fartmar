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
|         | "transfer-approve" | "transfer-dispatch" | "transfer-cancel"
|         | "transfer-receive" | "discrepancy-resolve"
|   create:    user_id, patient_id, prescription_id, warehouse_id,
|              items: [[prescription_item_id, quantity], ...], idempotency_key
|   authorize: user_id (regente), dispensation_id
|   transfer-*: user_id, transfer_id (transfer-cancel: reason;
|              transfer-receive: received: [[transfer_item_lot_id, cantidad], ...])
|   discrepancy-resolve: user_id (regente), discrepancy_id,
|              action (REINTEGRAR_ORIGEN | PERDIDA), resolution
|   barrier (int, clave del advisory lock), unsafe (bool, opcional)
|
| Pasos:
|   1. Arranca Laravel y abre la conexión (lo lento ocurre ANTES de la barrera).
|   2. Espera en la barrera: pg_advisory_lock_shared(barrier). La prueba tiene
|      ese lock en modo exclusivo y lo suelta cuando TODOS los workers están
|      esperando, así arrancan prácticamente a la vez.
|   3. Llama a DispenseService::create / ::authorize o a TransferService
|      (approve, dispatch, cancel, receive, resolveDiscrepancy)
|      (código real de producción).
|   4. Imprime UNA línea JSON con el resultado y termina con código 0.
|      Solo un error inesperado (no de negocio, CHECK ni UNIQUE) termina con código 1.
|
| unsafe = true SOLO existe para demostrar que la prueba detecta el problema:
| quita todos los FOR UPDATE de este proceso (gramática SQL sin bloqueo) y
| agrega una pausa tras cada lectura de stocks o de tablas de traslados
| (transfers, transfer_item_lots, transfer_discrepancies...) para que todos
| lean el mismo saldo / estado antes de escribir. Sin bloqueos, la BD
| (CHECK quantity >= 0 y triggers de traslados, todos SQLSTATE 23514) debe
| rechazar las operaciones sobrantes. No toca el código de producción.
*/

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Domain\Dispensing\DispenseService;
use App\Domain\Exceptions\BusinessRuleException;
use App\Domain\Transfers\TransferService;
use App\Enums\DiscrepancyResolution;
use App\Models\Dispensation;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
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

/** @var array{mode?: string, user_id: int, patient_id?: int, prescription_id?: int, warehouse_id?: int, items?: list<array{0: int, 1: int}>, idempotency_key?: string, dispensation_id?: int, transfer_id?: int, reason?: string, received?: list<array{0: int, 1: int}>, discrepancy_id?: int, action?: string, resolution?: string, barrier: int, unsafe?: bool} $job */
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
        // Tras leer existencias o datos de traslados: todos leen el mismo
        // estado (saldo, EN_TRANSITO, PENDIENTE) antes de que alguno escriba.
        if (str_starts_with($query->sql, 'select') && (str_contains($query->sql, '"stocks"') || str_contains($query->sql, '"transfer'))) {
            usleep(300_000);
        }
    });
}

$user = User::query()->findOrFail($job['user_id']);

// Lo que lee la BD se prepara ANTES de la barrera; después solo corre la acción.
$mode = $job['mode'] ?? 'create';

if (str_starts_with($mode, 'transfer-')) {
    $transfer = Transfer::query()->findOrFail($job['transfer_id']);
    $service = app(TransferService::class);
    $action = function () use ($mode, $service, $transfer, $user, $job): array {
        $done = match ($mode) {
            'transfer-approve' => $service->approve($transfer, $user),
            'transfer-dispatch' => $service->dispatch($transfer, $user),
            'transfer-cancel' => $service->cancel($transfer, $user, $job['reason'] ?? 'Anulado en prueba de concurrencia'),
            'transfer-receive' => $service->receive($transfer, $user, array_column($job['received'] ?? [], 1, 0)),
            default => throw new InvalidArgumentException("Modo desconocido: {$mode}"),
        };

        return ['transfer_id' => $done->id, 'status' => $done->status->value];
    };
} elseif ($mode === 'discrepancy-resolve') {
    $discrepancy = TransferDiscrepancy::query()->findOrFail($job['discrepancy_id']);
    $resolution = DiscrepancyResolution::from($job['action'] ?? DiscrepancyResolution::ReintegrarOrigen->value);
    $action = fn (): array => ['discrepancy_id' => app(TransferService::class)->resolveDiscrepancy(
        $discrepancy,
        $user,
        $resolution,
        $job['resolution'] ?? 'Resuelta en prueba de concurrencia',
    )->id];
} elseif ($mode === 'authorize') {
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

    // SQLSTATE 23505 = unique_violation: un UNIQUE de la BD frenó la operación
    // (solo se espera en los workers "unsafe", sin bloqueos).
    if ($e->getCode() === '23505') {
        respond(['outcome' => 'UNIQUE_VIOLATION', 'message' => $e->getMessage()]);
    }

    respond(['outcome' => 'UNEXPECTED', 'sqlstate' => $e->getCode(), 'message' => $e->getMessage()], 1);
} catch (Throwable $e) {
    respond(['outcome' => 'UNEXPECTED', 'class' => $e::class, 'message' => $e->getMessage()], 1);
}
