<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Providers\MockLlmProvider;
use App\Domain\Exceptions\AssistantUnavailableException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Crea el proveedor según su nombre (LLM_PROVIDER o --provider del eval).
 */
final class LlmProviderFactory
{
    /** @var array<string, class-string<LlmProvider>> */
    public const PROVIDERS = [
        'mock' => MockLlmProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function make(string $name): LlmProvider
    {
        $class = self::PROVIDERS[strtolower(trim($name))] ?? null;

        if ($class === null) {
            // Configuración inválida: 503 comprensible, no un 500.
            Log::warning('assistant.unknown_provider', ['provider' => $name]);

            throw AssistantUnavailableException::forProvider($name);
        }

        return $this->container->make($class);
    }
}
