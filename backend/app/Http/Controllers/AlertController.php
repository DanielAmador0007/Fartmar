<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\AlertService;
use App\Domain\Inventory\BusinessDate;
use App\Http\Requests\IndexAlertRequest;
use App\Http\Resources\AlertsResource;

class AlertController extends Controller
{
    public function index(IndexAlertRequest $request, AlertService $alerts): AlertsResource
    {
        $warehouseId = $request->warehouseId();
        $limit = $request->limit();

        return new AlertsResource([
            'today' => BusinessDate::today()->toDateString(),
            'expiry_days' => $alerts->expiryDays(),
            'limit' => $limit,
            'expiring' => $alerts->expiringLots($warehouseId, $limit),
            'expiring_total' => $alerts->countExpiringLots($warehouseId),
            'low_stock' => $alerts->lowStock($warehouseId, $limit),
            'low_stock_total' => $alerts->countLowStock($warehouseId),
        ]);
    }
}
