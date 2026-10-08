<?php

namespace App\Providers;

use App\Models\Dispensation;
use App\Models\Lot;
use App\Models\Patient;
use App\Models\PersonalAccessToken;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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

        $this->configureAuthentication();
    }

    /**
     * Autenticación por token Bearer de Sanctum (sin cookies de sesión).
     */
    private function configureAuthentication(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // S-37: un usuario desactivado deja de autenticar aunque conserve un
        // token vigente (no solo pierde permisos en las Policies: tampoco
        // puede usar /auth/me ni ningún otro endpoint protegido).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && $token->tokenable instanceof User
                && $token->tokenable->is_active,
        );

        // API autenticada: N solicitudes por minuto por usuario (por IP si,
        // por algún motivo, no hay usuario). Corre después de auth:sanctum.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute((int) config('fartmar.api_rate_limit_per_minute', 120))
            ->by('api:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Login: 5 intentos por minuto por correo + IP (fuerza bruta sobre una
        // cuenta) y 20 por minuto por IP (barrido de muchas cuentas).
        RateLimiter::for('login', function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:'.sha1($email.'|'.$request->ip())),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
