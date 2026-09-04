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
 * Los tipos de los arrays van completos a propósito: el análisis estático del
 * ecosistema corre a nivel 8, y un `array` a secas en un `@method` deja al
 * consumidor sin ninguna comprobación sobre lo que le pasa al cliente — que es
 * justo donde viaja el prompt.
 *
 * @method static string chat(array<int, array{role: string, content: string}> $messages, array<string, mixed> $options = [])
 * @method static array<string, mixed> raw(array<int, array{role: string, content: string}> $messages, array<string, mixed> $options = [])
 * @method static Generator<int, string> stream(array<int, array{role: string, content: string}> $messages, array<string, mixed> $options = [])
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
