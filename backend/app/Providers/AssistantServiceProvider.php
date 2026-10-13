<?php

namespace App\Providers;

use App\Domain\Assistant\LlmProvider;
use App\Domain\Assistant\LlmProviderFactory;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Asistente IA: qué proveedor de modelo se usa (config assistant.provider,
 * variable LLM_PROVIDER), quién puede usarlo y su límite de solicitudes.
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            LlmProvider::class,
            fn ($app) => $app->make(LlmProviderFactory::class)->make((string) config('assistant.provider', 'mock')),
        );
    }

    public function boot(): void
    {
        // S-89: el asistente responde consultas de inventario, así que lo usa
        // quien puede leer inventario (StockPolicy: farmacia y auditor).
        Gate::define('useAssistant', fn (User $user): bool => $user->can('viewAny', Stock::class));

        // Cada pregunta puede costar varias llamadas a un modelo (tiempo o dinero).
        RateLimiter::for('assistant', fn (Request $request): Limit => Limit::perMinute((int) config('assistant.rate_limit_per_minute', 10))
            ->by('assistant:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
