<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\AdjustmentService;
use App\Http\Requests\StoreAdjustmentRequest;
use App\Http\Resources\KardexMovementResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * POST /inventory-adjustments: el resultado de un ajuste es su movimiento
 * AJUSTE de kardex (201).
 */
class InventoryAdjustmentController extends Controller
{
    public function store(StoreAdjustmentRequest $request, AdjustmentService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $movement = $service->adjust(
            $user,
            $request->integer('warehouse_id'),
            $request->integer('lot_id'),
            $request->integer('quantity'),
            $request->string('reason')->toString(),
        );

        return (new KardexMovementResource($movement->load(KardexMovementResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }
}
