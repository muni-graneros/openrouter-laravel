<?php

namespace Muni\OpenRouter;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the OpenRouter client into a host Laravel application.
 *
 * Registered automatically through package discovery, so a host app only needs
 * to require the package and set OPENROUTER_KEY.
 */
class OpenRouterServiceProvider extends ServiceProvider
{
    /**
     * Publishing tag for the package configuration.
     */
    public const CONFIG_TAG = 'openrouter-config';

    public function register(): void
    {
        // Merged rather than required: a host app that never publishes the file
        // still gets working defaults, and one that publishes it only has to keep
        // the keys it actually wants to change.
        $this->mergeConfigFrom(__DIR__.'/../config/openrouter.php', 'openrouter');

        $this->app->singleton(
            OpenRouterClient::class,
            static fn (): OpenRouterClient => OpenRouterClient::fromConfig(),
        );

        // The facade accessor. Bound to the same instance so injecting the class
        // and calling the facade cannot end up talking to two different clients.
        $this->app->alias(OpenRouterClient::class, 'openrouter');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/openrouter.php' => $this->app->configPath('openrouter.php'),
            ], self::CONFIG_TAG);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [OpenRouterClient::class, 'openrouter'];
    }
}
