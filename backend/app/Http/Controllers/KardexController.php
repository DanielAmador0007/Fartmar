<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\KardexQuery;
use App\Http\Requests\IndexKardexRequest;
use App\Http\Resources\KardexMovementResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KardexController extends Controller
{
    public function index(IndexKardexRequest $request, KardexQuery $kardex): AnonymousResourceCollection
    {
        return KardexMovementResource::collection($kardex->search($request->filters()));
    }
}
