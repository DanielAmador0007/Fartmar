<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogQuery;
use App\Http\Requests\IndexProductRequest;
use App\Http\Requests\IndexWarehouseRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\WarehouseSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogos de solo lectura (bodegas y productos). Valida y autoriza en el
 * Form Request; la consulta vive en CatalogQuery.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function warehouses(IndexWarehouseRequest $request): AnonymousResourceCollection
    {
        return WarehouseSummaryResource::collection($this->catalog->warehouses($request->filters()));
    }

    public function products(IndexProductRequest $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->catalog->products($request->filters()));
    }
}
