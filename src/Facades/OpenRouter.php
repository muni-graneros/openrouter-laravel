<?php

namespace Muni\OpenRouter\Facades;

use Generator;
use Illuminate\Support\Facades\Facade;
use Muni\OpenRouter\OpenRouterClient;

/**
 * Static entry point to the OpenRouter client.
 *
 * Resolves the same singleton the container injects, so the facade and an
 * injected OpenRouterClient are always the same object.
 *
 * @method static string chat(array $messages, array $options = [])
 * @method static array raw(array $messages, array $options = [])
 * @method static Generator stream(array $messages, array $options = [])
 *
 * @see OpenRouterClient
 */
class OpenRouter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'openrouter';
    }
}
