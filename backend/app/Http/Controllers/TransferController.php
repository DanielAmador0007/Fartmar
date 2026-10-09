<?php

namespace App\Http\Controllers;

use App\Domain\Transfers\TransferQuery;
use App\Domain\Transfers\TransferService;
use App\Http\Requests\CancelTransferRequest;
use App\Http\Requests\IndexTransferRequest;
use App\Http\Requests\ReceiveTransferRequest;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Controlador delgado de traslados (RN-07, RN-08): valida (Form Request),
 * autoriza (TransferPolicy), delega en TransferService y responde con
 * TransferResource. Las reglas (estados, FEFO, segregación) viven en el servicio.
 */
class TransferController extends Controller
{
    public function __construct(private readonly TransferService $service) {}

    public function index(IndexTransferRequest $request, TransferQuery $query): AnonymousResourceCollection
    {
        return TransferResource::collection($query->search($request->filters()));
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $transfer = $this->service->create($this->currentUser($request), $request->transferData());

        return $this->detail($transfer)->response()->setStatusCode(201);
    }

    public function show(Transfer $transfer): TransferResource
    {
        Gate::authorize('view', $transfer);

        return $this->detail($transfer);
    }

    public function submit(Request $request, Transfer $transfer): TransferResource
    {
        Gate::authorize('submit', $transfer);

        return $this->detail($this->service->submit($transfer, $this->currentUser($request)));
    }

    public function approve(Request $request, Transfer $transfer): TransferResource
    {
        Gate::authorize('approve', $transfer);

        return $this->detail($this->service->approve($transfer, $this->currentUser($request)));
    }

    public function dispatchTransfer(Request $request, Transfer $transfer): TransferResource
    {
        Gate::authorize('dispatch', $transfer);

        return $this->detail($this->service->dispatch($transfer, $this->currentUser($request)));
    }

    public function receive(ReceiveTransferRequest $request, Transfer $transfer): TransferResource
    {
        return $this->detail($this->service->receive($transfer, $this->currentUser($request), $request->receivedQuantities()));
    }

    public function cancel(CancelTransferRequest $request, Transfer $transfer): TransferResource
    {
        return $this->detail($this->service->cancel(
            $transfer,
            $this->currentUser($request),
            $request->string('reason')->toString(),
        ));
    }

    private function detail(Transfer $transfer): TransferResource
    {
        return new TransferResource($transfer->load(TransferResource::RELATIONS));
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
