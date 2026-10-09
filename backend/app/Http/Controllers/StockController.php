<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuery;
use App\Http\Requests\IndexStockRequest;
use App\Http\Resources\StockResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private readonly InventoryQuery $inventory) {}

    public function index(IndexStockRequest $request): AnonymousResourceCollection
    {
        return StockResource::collection($this->inventory->search($request->filters()));
    }
}
