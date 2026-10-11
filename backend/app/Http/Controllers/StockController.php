<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuery;
use App\Domain\Transfers\TransferQuery;
use App\Http\Requests\IndexInTransitStockRequest;
use App\Http\Requests\IndexStockRequest;
use App\Http\Resources\InTransitStockResource;
use App\Http\Resources\StockResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private readonly InventoryQuery $inventory) {}

    public function index(IndexStockRequest $request): AnonymousResourceCollection
    {
        return StockResource::collection($this->inventory->search($request->filters()));
    }

    /**
     * Stock en tránsito entre bodegas (solo lectura).
     */
    public function inTransit(IndexInTransitStockRequest $request, TransferQuery $transfers): AnonymousResourceCollection
    {
        return InTransitStockResource::collection($transfers->inTransit($request->filters()));
    }
}
