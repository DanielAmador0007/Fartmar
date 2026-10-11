<?php

namespace App\Http\Controllers;

use App\Domain\Transfers\TransferQuery;
use App\Domain\Transfers\TransferService;
use App\Http\Requests\IndexDiscrepancyRequest;
use App\Http\Requests\ResolveDiscrepancyRequest;
use App\Http\Resources\TransferDiscrepancyResource;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransferDiscrepancyController extends Controller
{
    public function index(IndexDiscrepancyRequest $request, TransferQuery $query): AnonymousResourceCollection
    {
        return TransferDiscrepancyResource::collection($query->discrepancies($request->filters()));
    }

    public function resolve(ResolveDiscrepancyRequest $request, TransferDiscrepancy $discrepancy, TransferService $service): TransferDiscrepancyResource
    {
        /** @var User $user */
        $user = $request->user();

        $resolved = $service->resolveDiscrepancy(
            $discrepancy,
            $user,
            $request->resolutionAction(),
            $request->string('resolution')->toString(),
        );

        return new TransferDiscrepancyResource($resolved->load(TransferDiscrepancyResource::RELATIONS));
    }
}
