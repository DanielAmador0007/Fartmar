<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispensationController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferDiscrepancyController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 (prefijo /api/v1). Autenticación con tokens de Sanctum
| (header Authorization: Bearer <token>). Permisos en app/Policies.
*/

// IDs de ruta: 1 a 18 dígitos (siempre caben en bigint). Con whereNumber un
// id enorme llegaba a PostgreSQL y respondía 500 en vez de 404.
Route::pattern('dispensation', '[1-9][0-9]{0,17}');
Route::pattern('transfer', '[1-9][0-9]{0,17}');
Route::pattern('discrepancy', '[1-9][0-9]{0,17}');
Route::pattern('patient', '[1-9][0-9]{0,17}');
Route::pattern('user', '[1-9][0-9]{0,17}');

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

        // Inventario (consulta). "in-transit" va antes de cualquier stocks/{param}.
        Route::get('stocks/in-transit', [StockController::class, 'inTransit'])->name('stocks.in-transit');
        Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');

        // Traslados entre bodegas (RN-07, RN-08).
        Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('transfers/{transfer}/submit', [TransferController::class, 'submit'])->name('transfers.submit');
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer'])->name('transfers.dispatch');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

        Route::get('transfer-discrepancies', [TransferDiscrepancyController::class, 'index'])->name('transfer-discrepancies.index');
        Route::post('transfer-discrepancies/{discrepancy}/resolve', [TransferDiscrepancyController::class, 'resolve'])
            ->name('transfer-discrepancies.resolve');

        // Ajustes, kardex y alertas (RN-06, RN-11).
        Route::post('inventory-adjustments', [InventoryAdjustmentController::class, 'store'])->name('inventory-adjustments.store');
        Route::get('kardex', [KardexController::class, 'index'])->name('kardex.index');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');

        // Pacientes (RN-10): enmascarado por rol y bitácora de cada lectura.
        // Búsqueda por POST para que el documento no quede en URLs ni logs de acceso (S-69).
        Route::post('patients/search', [PatientController::class, 'search'])->name('patients.search');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
        Route::get('patients/{patient}/prescriptions', [PatientController::class, 'prescriptions'])
            ->name('patients.prescriptions');
        Route::post('prescriptions', [PrescriptionController::class, 'store'])->name('prescriptions.store');

        // Administración de usuarios (solo admin; sin DELETE, se desactiva).
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
