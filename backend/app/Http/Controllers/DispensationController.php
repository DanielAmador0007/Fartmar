<?php

namespace App\Http\Controllers;

use App\Domain\Dispensing\DispenseService;
use App\Http\Requests\PreviewDispensationRequest;
use App\Http\Requests\RejectDispensationRequest;
use App\Http\Requests\StoreDispensationRequest;
use App\Http\Resources\DispensationResource;
use App\Http\Resources\FefoPreviewResource;
use App\Models\Dispensation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Controlador delgado: valida (Form Request), autoriza (Policy), delega en
 * DispenseService y responde con DispensationResource.
 */
class DispensationController extends Controller
{
    public function __construct(private readonly DispenseService $service) {}

    /**
     * 201 si se creó; 200 + "Idempotent-Replayed: true" si es un reintento
     * con la misma Idempotency-Key y el mismo contenido (RN-09).
     */
    public function store(StoreDispensationRequest $request): JsonResponse
    {
        $result = $this->service->create(
            $this->currentUser($request),
            $request->dispenseData(),
            $request->idempotencyKey(),
        );

        $response = (new DispensationResource($result->dispensation->load(DispensationResource::RELATIONS)))
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);

        if ($result->replayed) {
            $response->header('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    public function show(Dispensation $dispensation): DispensationResource
    {
        Gate::authorize('view', $dispensation);

        return new DispensationResource($dispensation->load(DispensationResource::RELATIONS));
    }

    /** RN-05: un regente distinto del creador autoriza un controlado. */
    public function authorizeControlled(Request $request, Dispensation $dispensation): DispensationResource
    {
        Gate::authorize('decide', $dispensation);

        $done = $this->service->authorize($dispensation, $this->currentUser($request));

        return new DispensationResource($done->load(DispensationResource::RELATIONS));
    }

    public function reject(RejectDispensationRequest $request, Dispensation $dispensation): DispensationResource
    {
        $rejected = $this->service->reject(
            $dispensation,
            $this->currentUser($request),
            $request->string('reason')->trim()->toString(),
        );

        return new DispensationResource($rejected->load(DispensationResource::RELATIONS));
    }

    /** Vista previa FEFO: qué lotes saldrían, sin mover stock. */
    public function preview(PreviewDispensationRequest $request): FefoPreviewResource
    {
        $preview = $this->service->preview(
            $request->integer('warehouse_id'),
            Product::query()->findOrFail($request->integer('product_id')),
            $request->integer('quantity'),
        );

        return new FefoPreviewResource($preview);
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
