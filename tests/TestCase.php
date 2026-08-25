<?php

namespace Muni\OpenRouter\Tests;

use Illuminate\Foundation\Application;
use Muni\OpenRouter\OpenRouterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a minimal Laravel application with only this package registered, so the
 * suite runs without a host app around it.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OpenRouterServiceProvider::class];
    }
}
