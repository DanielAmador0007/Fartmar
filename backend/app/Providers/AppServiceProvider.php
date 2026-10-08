<?php

namespace App\Providers;

use App\Models\Dispensation;
use App\Models\Lot;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nombres cortos y estables en columnas polimórficas (kardex_movements.reference_type,
        // audit_logs.auditable_type) en vez de nombres de clase PHP.
        Relation::enforceMorphMap([
            'dispensation' => Dispensation::class,
            'transfer' => Transfer::class,
            'transfer_discrepancy' => TransferDiscrepancy::class,
            'stock' => Stock::class,
            'prescription' => Prescription::class,
            'patient' => Patient::class,
            'product' => Product::class,
            'lot' => Lot::class,
            'warehouse' => Warehouse::class,
            'user' => User::class,
        ]);
    }
}
