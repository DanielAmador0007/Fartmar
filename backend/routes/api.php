<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispensationController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 (prefijo /api/v1). Autenticación con tokens de Sanctum
| (header Authorization: Bearer <token>). Permisos en app/Policies.
*/

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Dispensación (RN-01..RN-06, RN-09). "preview" va antes de {dispensation}.
        Route::get('dispensations/preview', [DispensationController::class, 'preview'])->name('dispensations.preview');
        Route::post('dispensations', [DispensationController::class, 'store'])->name('dispensations.store');
        Route::get('dispensations/{dispensation}', [DispensationController::class, 'show'])
            ->whereNumber('dispensation')->name('dispensations.show');
        Route::post('dispensations/{dispensation}/authorize', [DispensationController::class, 'authorizeControlled'])
            ->whereNumber('dispensation')->name('dispensations.authorize');
        Route::post('dispensations/{dispensation}/reject', [DispensationController::class, 'reject'])
            ->whereNumber('dispensation')->name('dispensations.reject');

        // Inventario (consulta).
        Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    });
});
