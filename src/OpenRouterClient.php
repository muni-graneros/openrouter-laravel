<?php

namespace Muni\OpenRouter;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls large language models through OpenRouter's OpenAI-compatible API.
 *
 * Usage:
 *
 *     use Muni\OpenRouter\OpenRouterClient;
 *
 *     public function __construct(private readonly OpenRouterClient $openRouter) {}
 *
 *     // Plain completion, default model from config.
 *     $text = $this->openRouter->chat([
 *         ['role' => 'system', 'content' => 'Answer in one sentence.'],
 *         ['role' => 'user', 'content' => 'What is an ordinance?'],
 *     ]);
 *
 *     // Per-call model and sampling options.
 *     $text = $this->openRouter->chat($messages, [
 *         'model' => 'anthropic/claude-sonnet-4.5',
 *         'temperature' => 0.2,
 *     ]);
 *
 *     // Full decoded payload, when usage or finish_reason matters.
 *     $payload = $this->openRouter->raw($messages);
 *     $tokens = $payload['usage']['total_tokens'] ?? null;
 *
 *     // Token-by-token, for SSE controllers.
 *     foreach ($this->openRouter->stream($messages) as $token) {
 *         echo $token;
 *     }
 *
 *     // Or through the facade, when injecting is not convenient:
 *     use Muni\OpenRouter\Facades\OpenRouter;
 *
 *     $text = OpenRouter::chat($messages);
 *
 * Anything that cannot be recovered from throws OpenRouterException, which
 * carries the upstream status and message.
 *
 * Privacy note: everything in $messages leaves this server and reaches a third
 * party. Do not put personal data in a prompt without a lawful basis for it
 * (Ley 21.719); OPENROUTER_EXCLUDE_LOGGING restricts routing to providers that
 * do not train on or retain prompts, but it does not make the transfer lawful
 * on its own.
 */
class OpenRouterClient
{
    /**
     * How many times a request is attempted before giving up.
     *
     * Only transient network failures are retried. A response that arrived —
     * including a 429 — is never retried here: rate limiting is handled by the
     * fallback model instead, and hammering a rate-limited endpoint would only
     * make it worse.
     */
    private const ATTEMPTS = 3;

    /**
     * Milliseconds between network retries.
     */
    private const RETRY_DELAY_MS = 250;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl = 'https://openrouter.ai/api/v1',
        private readonly string $defaultModel = 'openrouter/free',
        private readonly ?string $fallbackModel = null,
        private readonly bool $excludeLogging = false,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Build the client from the package configuration.
     *
     * Used by the container binding in OpenRouterServiceProvider; the constructor
     * stays explicit so tests can build a client without touching configuration.
     */
    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('openrouter.key'),
            baseUrl: config('openrouter.base_url'),
            defaultModel: config('openrouter.default_model'),
            fallbackModel: config('openrouter.fallback_model'),
            excludeLogging: config('openrouter.exclude_logging'),
            timeout: config('openrouter.timeout'),
        );
    }

    /**
     * Send a conversation and return the assistant's reply as text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  Extra body parameters; 'model' overrides the configured default.
     *
     * @throws OpenRouterException
     */
    public function chat(array $messages, array $options = []): string
    {
        $payload = $this->raw($messages, $options);

        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content)) {
            throw new OpenRouterException(
                'OpenRouter returned a response without assistant text.',
                200,
                $this->modelFor($options),
            );
        }

        return $content;
    }

    /**
     * Send a conversation and return the full decoded response.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws OpenRouterException
     */
    public function raw(array $messages, array $options = []): array
    {
        $model = $this->modelFor($options);
        $response = $this->post($messages, $options, $model);

        // Free tiers run out. When they do, and a paid model is configured, the
        // call is worth retrying once on that model rather than failing the
        // feature — but only once, and only for free models: retrying a paid
        // model that is rate limited would just spend money on the same error.
        if ($response->status() === 429 && $this->isFreeModel($model) && $this->fallbackModel !== null) {
            Log::warning('OpenRouter rate limited the free model; retrying with the fallback model.', [
                'rate_limited_model' => $model,
                'fallback_model' => $this->fallbackModel,
            ]);

            $model = $this->fallbackModel;
            $response = $this->post($messages, $options, $model);
        }

        if ($response->failed()) {
            throw OpenRouterException::fromResponse($response, $model);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new OpenRouterException('OpenRouter returned a body that is not JSON.', $response->status(), $model);
        }

        return $payload;
    }

    /**
     * Stream the assistant's reply, yielding each token as it arrives.
     *
     * Meant for SSE controllers: iterate and echo. No fallback model here — by
     * the time the first token is out there is nothing left to fall back to, so
     * a stream that starts on a rate-limited model simply fails.
     *
     * The configured timeout covers the whole stream, not just the connection,
     * so long generations need it raised in config/services.php.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return Generator<int, string>
     *
     * @throws OpenRouterException
     */
    public function stream(array $messages, array $options = []): Generator
    {
        $model = $this->modelFor($options);

        $response = $this->post($messages, $options, $model, stream: true);

        if ($response->failed()) {
            throw OpenRouterException::fromResponse($response, $model);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            // A stream that reports "not at the end" but hands back nothing has
            // stalled; without this the loop would spin forever.
            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                // OpenRouter sends ": OPENROUTER PROCESSING" comment lines as
                // keep-alives while a provider is still thinking.
                if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, strlen('data:')));

                if ($data === '[DONE]') {
                    return;
                }

                $token = json_decode($data, true)['choices'][0]['delta']['content'] ?? null;

                if (is_string($token) && $token !== '') {
                    yield $token;
                }
            }
        }
    }

    /**
     * Perform one POST to /chat/completions.
     *
     * Returns the response whatever its status — deciding what a failure means
     * is the caller's job, because a 429 is recoverable here and a 500 is not.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     *
     * @throws OpenRouterException
     */
    private function post(array $messages, array $options, string $model, bool $stream = false): Response
    {
        $request = $this->request();

        if ($stream) {
            $request->withOptions(['stream' => true]);
        }

        try {
            return $request->post('/chat/completions', $this->body($messages, $options, $model, $stream));
        } catch (ConnectionException $exception) {
            throw OpenRouterException::fromConnectionFailure($exception, $model);
        }
    }

    /**
     * The configured HTTP client.
     *
     * `throw: false` on the retry is deliberate: it keeps a failed response as a
     * response object instead of an exception, which is what lets raw() inspect
     * a 429 and decide to fall back.
     *
     * @throws OpenRouterException
     */
    private function request(): PendingRequest
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw OpenRouterException::missingApiKey();
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                // Optional attribution headers OpenRouter uses to label traffic
                // in the dashboard. Neither carries a secret.
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name'),
            ])
            ->timeout($this->timeout)
            ->retry(
                times: self::ATTEMPTS,
                sleepMilliseconds: self::RETRY_DELAY_MS,
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * The request body for /chat/completions.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function body(array $messages, array $options, string $model, bool $stream): array
    {
        // 'model' is consumed here, not forwarded: everything else in $options
        // is a legitimate OpenAI-compatible parameter (temperature, max_tokens,
        // response_format…) and is passed through untouched.
        unset($options['model']);

        $body = [
            ...$options,
            'model' => $model,
            'messages' => array_values($messages),
        ];

        if ($stream) {
            $body['stream'] = true;
        }

        if ($this->excludeLogging) {
            // OpenRouter's data policy controls, sent in the body (they are not
            // headers). 'data_collection' => 'deny' drops providers that may
            // store or train on the prompt; 'zdr' => true narrows it further to
            // endpoints that retain nothing at all. Any caller-supplied
            // 'provider' block is preserved, but these two keys win.
            // https://openrouter.ai/docs/features/provider-routing
            $provider = is_array($options['provider'] ?? null) ? $options['provider'] : [];

            $body['provider'] = [
                ...$provider,
                'data_collection' => 'deny',
                'zdr' => true,
            ];
        }

        return $body;
    }

    /**
     * The model this call should use.
     *
     * @param  array<string, mixed>  $options
     */
    private function modelFor(array $options): string
    {
        $model = $options['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : $this->defaultModel;
    }

    /**
     * Whether a model is billed at zero, and therefore worth falling back from.
     *
     * OpenRouter marks free variants with a ':free' suffix
     * ('meta-llama/llama-3.3-70b-instruct:free'), and also exposes 'openrouter/free'
     * as a meta-model that routes across whatever free capacity exists.
     */
    private function isFreeModel(string $model): bool
    {
        return str_ends_with($model, ':free') || str_starts_with($model, 'openrouter/free');
    }
}
