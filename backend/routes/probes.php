<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Sondas de liveness/readiness. Se cargan SIN el grupo de middleware "web"
// (sin sesión ni cookies) y sin autenticación. Ver bootstrap/app.php.
Route::get('/health', [HealthController::class, 'health'])->name('probes.health');
Route::get('/ready', [HealthController::class, 'ready'])->name('probes.ready');
