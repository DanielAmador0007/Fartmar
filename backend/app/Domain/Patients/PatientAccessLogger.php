<?php

namespace App\Domain\Patients;

use App\Models\Patient;
use App\Models\PatientAccessLog;
use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Bitácora de quién consultó qué paciente (RN-10), en patient_access_logs
 * (append-only por trigger).
 *
 * Se llama DESPUÉS de autorizar (una lectura negada no se registra como
 * acceso) y ANTES de responder. La fila solo guarda IDs, la acción, si los
 * datos se vieron enmascarados, la IP y el correlation_id (middleware
 * AssignCorrelationId): ningún dato personal del paciente.
 */
final class PatientAccessLogger
{
    public const SEARCH = 'patient.search';

    public const VIEW = 'patient.view';

    public const PRESCRIPTIONS = 'patient.prescriptions';

    /** GET /dispensations/{id}: expone el paciente y lo que se le entregó. */
    public const DISPENSATION_VIEW = 'patient.dispensation_view';

    public function record(User $user, Patient $patient, string $action): PatientAccessLog
    {
        return PatientAccessLog::query()->create([
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'action' => $action,
            // La misma regla con la que PatientResource decide qué mostrar.
            'masked' => $user->cannot('viewUnmasked', $patient),
            'ip' => Context::get('ip'),
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
