<?php

namespace App\Http\Controllers;

use App\Domain\Prescriptions\PrescriptionQuery;
use App\Domain\Prescriptions\PrescriptionService;
use App\Http\Requests\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PrescriptionController extends Controller
{
    public function __construct(
        private readonly PrescriptionService $service,
        private readonly PrescriptionQuery $query,
    ) {}

    /** Solo médico (StorePrescriptionRequest::authorize -> PrescriptionPolicy). */
    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $prescription = $this->service->create(
            $user,
            $request->integer('patient_id'),
            $request->validUntil(),
            $request->prescriptionItems(),
        );

        return (new PrescriptionResource($this->query->load($prescription)))
            ->response()
            ->setStatusCode(201);
    }
}
