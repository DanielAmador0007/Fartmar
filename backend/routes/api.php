<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispensationController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 (prefijo /api/v1). Autenticación con tokens de Sanctum
| (header Authorization: Bearer <token>). Permisos en app/Policies.
*/

// IDs de ruta: 1 a 18 dígitos (siempre caben en bigint). Con whereNumber un
// id enorme llegaba a PostgreSQL y respondía 500 en vez de 404.
Route::pattern('dispensation', '[1-9][0-9]{0,17}');

Route::prefix('v1')->group(function (): void {
    // Limitador "login" (AppServiceProvider): por correo + IP y por IP.
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // auth:sanctum primero para que el límite "api" cuente por usuario.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Dispensación (RN-01..RN-06, RN-09). "preview" va antes de {dispensation}.
        Route::get('dispensations/preview', [DispensationController::class, 'preview'])->name('dispensations.preview');
        Route::post('dispensations', [DispensationController::class, 'store'])->name('dispensations.store');
        Route::get('dispensations/{dispensation}', [DispensationController::class, 'show'])
            ->name('dispensations.show');
        Route::post('dispensations/{dispensation}/authorize', [DispensationController::class, 'authorizeControlled'])
            ->name('dispensations.authorize');
        Route::post('dispensations/{dispensation}/reject', [DispensationController::class, 'reject'])
            ->name('dispensations.reject');

        // Inventario (consulta).
        Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    });
});
