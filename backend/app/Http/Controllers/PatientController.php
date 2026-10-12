<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Patients\PatientAccessLogger;
use App\Domain\Patients\PatientFinder;
use App\Domain\Prescriptions\PrescriptionQuery;
use App\Http\Requests\SearchPatientRequest;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Lectura de pacientes (RN-10). Cada lectura autorizada queda en
 * patient_access_logs; PatientResource enmascara según el rol.
 */
class PatientController extends Controller
{
    public function __construct(
        private readonly PatientAccessLogger $accessLog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Búsqueda exacta por documento: { data: [] } o { data: [paciente] }.
     */
    public function search(SearchPatientRequest $request, PatientFinder $finder): AnonymousResourceCollection
    {
        $user = $this->currentUser($request);
        $patient = $finder->findByDocument(
            $request->string('document_type')->toString(),
            $request->string('document_number')->toString(),
        );

        if ($patient === null) {
            // Sin paciente no hay fila en patient_access_logs (exige patient_id);
            // se audita la búsqueda fallida (sin el número) para detectar barridos.
            $this->audit->record($user, 'patient.search_not_found', null, [
                'document_type' => $request->string('document_type')->toString(),
            ]);

            return PatientResource::collection([]);
        }

        $this->accessLog->record($user, $patient, PatientAccessLogger::SEARCH);

        return PatientResource::collection([$patient]);
    }

    public function show(Request $request, Patient $patient): PatientResource
    {
        Gate::authorize('view', $patient);
        $this->accessLog->record($this->currentUser($request), $patient, PatientAccessLogger::VIEW);

        return new PatientResource($patient);
    }

    /** Prescripciones con cantidades pendientes, para dispensar. */
    public function prescriptions(Request $request, Patient $patient, PrescriptionQuery $query): AnonymousResourceCollection
    {
        Gate::authorize('view', $patient);
        $this->accessLog->record($this->currentUser($request), $patient, PatientAccessLogger::PRESCRIPTIONS);

        return PrescriptionResource::collection($query->forPatient($patient));
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
