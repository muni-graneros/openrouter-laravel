<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Muni\OpenRouter\Facades\OpenRouter;
use Muni\OpenRouter\OpenRouterClient;
use Muni\OpenRouter\OpenRouterException;
use Muni\OpenRouter\OpenRouterServiceProvider;

/**
 * Build a client without touching configuration, so every test states exactly
 * the setup it depends on.
 */
function openRouterClient(array $overrides = []): OpenRouterClient
{
    $settings = [
        'apiKey' => 'test-key',
        'baseUrl' => 'https://openrouter.ai/api/v1',
        'defaultModel' => 'openrouter/free',
        'fallbackModel' => null,
        'excludeLogging' => false,
        'timeout' => 5,
        ...$overrides,
    ];

    return new OpenRouterClient(...$settings);
}

/**
 * A successful /chat/completions body.
 */
function openRouterCompletion(string $text, string $model = 'openrouter/free'): array
{
    return [
        'id' => 'gen-123',
        'model' => $model,
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4, 'total_tokens' => 15],
    ];
}

const OPENROUTER_MESSAGES = [
    ['role' => 'user', 'content' => 'Say hello.'],
];

it('returns the assistant text of a normal completion', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(openRouterCompletion('Hello there.')),
    ]);

    $text = openRouterClient()->chat(OPENROUTER_MESSAGES);

    expect($text)->toBe('Hello there.');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'openrouter/free'
            && $request['messages'] === OPENROUTER_MESSAGES
            && ! isset($request['stream'])
            && ! isset($request['provider']);
    });

    Http::assertSentCount(1);
});

it('returns the full payload from raw()', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(openRouterCompletion('Hello there.')),
    ]);

    $payload = openRouterClient()->raw(OPENROUTER_MESSAGES);

    expect($payload['usage']['total_tokens'])->toBe(15)
        ->and($payload['choices'][0]['message']['content'])->toBe('Hello there.');
});

it('lets the caller override the model and pass extra options', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(openRouterCompletion('Ok.', 'anthropic/claude-sonnet-4.5')),
    ]);

    openRouterClient()->chat(OPENROUTER_MESSAGES, [
        'model' => 'anthropic/claude-sonnet-4.5',
        'temperature' => 0.2,
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request['model'] === 'anthropic/claude-sonnet-4.5'
            && $request['temperature'] === 0.2;
    });
});

it('asks OpenRouter for providers that do not retain prompts when logging is excluded', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(openRouterCompletion('Ok.')),
    ]);

    openRouterClient(['excludeLogging' => true])->chat(OPENROUTER_MESSAGES);

    Http::assertSent(function (Request $request): bool {
        return $request['provider'] === ['data_collection' => 'deny', 'zdr' => true];
    });
});

it('retries once with the fallback model when the free model is rate limited', function () {
    Log::spy();

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit exceeded', 'code' => 429]], 429)
            ->push(openRouterCompletion('Answered by the paid model.', 'anthropic/claude-sonnet-4.5')),
    ]);

    $text = openRouterClient(['fallbackModel' => 'anthropic/claude-sonnet-4.5'])
        ->chat(OPENROUTER_MESSAGES);

    expect($text)->toBe('Answered by the paid model.');

    Http::assertSentCount(2);

    $models = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]['model'])->all();

    expect($models)->toBe(['openrouter/free', 'anthropic/claude-sonnet-4.5']);

    Log::shouldHaveReceived('warning')->once();
});

it('does not fall back twice when the fallback model is also rate limited', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
    ]);

    expect(fn () => openRouterClient(['fallbackModel' => 'anthropic/claude-sonnet-4.5'])->chat(OPENROUTER_MESSAGES))
        ->toThrow(OpenRouterException::class);

    Http::assertSentCount(2);
});

it('does not fall back when the rate limited model is a paid one', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
    ]);

    expect(fn () => openRouterClient([
        'defaultModel' => 'anthropic/claude-sonnet-4.5',
        'fallbackModel' => 'openai/gpt-4.1',
    ])->chat(OPENROUTER_MESSAGES))->toThrow(OpenRouterException::class);

    Http::assertSentCount(1);
});

it('throws with the upstream status and message on a hard error', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Internal provider failure']], 500),
    ]);

    try {
        openRouterClient()->chat(OPENROUTER_MESSAGES);
        $this->fail('Expected OpenRouterException to be thrown.');
    } catch (OpenRouterException $exception) {
        expect($exception->status())->toBe(500)
            ->and($exception->model())->toBe('openrouter/free')
            ->and($exception->getMessage())->toContain('Internal provider failure')
            ->and($exception->getMessage())->toContain('status 500');
    }

    // A 500 is not a rate limit: it must not burn the paid fallback.
    Http::assertSentCount(1);
});

it('reports a network failure as an OpenRouterException after retrying', function () {
    // Counted here rather than with Http::assertSentCount(): a fake that throws
    // never produces a response, so the attempt is not recorded.
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 6: Could not resolve host');
    });

    try {
        openRouterClient()->chat(OPENROUTER_MESSAGES);
        $this->fail('Expected OpenRouterException to be thrown.');
    } catch (OpenRouterException $exception) {
        expect($exception->status())->toBe(0)
            ->and($exception->getMessage())->toContain('Could not reach OpenRouter');
    }

    // Transient network errors are the only thing worth retrying.
    expect($attempts)->toBe(3);
});

it('refuses to send anything when no api key is configured', function () {
    Http::fake();

    expect(fn () => openRouterClient(['apiKey' => null])->chat(OPENROUTER_MESSAGES))
        ->toThrow(OpenRouterException::class, 'OPENROUTER_KEY');

    Http::assertNothingSent();
});

it('yields tokens as they arrive when streaming', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(implode("\n", [
            ': OPENROUTER PROCESSING',
            'data: {"choices":[{"delta":{"content":"Hola"}}]}',
            '',
            'data: {"choices":[{"delta":{"content":" mundo"}}]}',
            '',
            'data: [DONE]',
            '',
        ])),
    ]);

    $tokens = iterator_to_array(openRouterClient()->stream(OPENROUTER_MESSAGES));

    expect($tokens)->toBe(['Hola', ' mundo']);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true);
});

it('is resolvable from the container using the package config', function () {
    config()->set('openrouter', [
        'key' => 'key-from-config',
        'base_url' => 'https://openrouter.ai/api/v1',
        'default_model' => 'openrouter/free',
        'fallback_model' => null,
        'exclude_logging' => false,
        'timeout' => 5,
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response(openRouterCompletion('From the container.')),
    ]);

    expect(app(OpenRouterClient::class)->chat(OPENROUTER_MESSAGES))->toBe('From the container.');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer key-from-config'));
});

it('exposes the same singleton through the facade and the container', function () {
    expect(OpenRouter::getFacadeRoot())->toBe(app(OpenRouterClient::class));
});

it('publishes its config under the openrouter-config tag', function () {
    $publicados = ServiceProvider::pathsToPublish(OpenRouterServiceProvider::class, 'openrouter-config');

    expect($publicados)->toHaveCount(1)
        ->and(array_key_first($publicados))->toEndWith('config/openrouter.php')
        ->and(reset($publicados))->toEndWith('config/openrouter.php');
});
