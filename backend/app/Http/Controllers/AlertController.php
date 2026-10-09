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

        return new AlertsResource([
            'today' => BusinessDate::today()->toDateString(),
            'expiry_days' => $alerts->expiryDays(),
            'expiring' => $alerts->expiringLots($warehouseId),
            'low_stock' => $alerts->lowStock($warehouseId),
        ]);
    }
}
