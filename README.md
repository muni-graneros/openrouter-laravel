# OpenRouter for Laravel

A small, explicit client for [OpenRouter](https://openrouter.ai) — one API key for
many LLM providers, over the OpenAI-compatible `/chat/completions` endpoint.

It exists to make three things automatic:

- **Free by default.** Calls go to `openrouter/free` unless you name a model.
- **Survives the free tier.** A `429` on a free model retries once on a paid
  fallback model, if you configured one, and logs that it happened.
- **Keeps prompts out of training data.** One flag restricts routing to providers
  that neither train on nor retain prompts.

No new dependencies: it uses Laravel's own HTTP client.

## Requirements

- PHP 8.4+
- Laravel 13

## Installation

The package is not on Packagist. Add it as a repository, then require it.

**From a local checkout** (what you want while developing the package):

```jsonc
// composer.json
"repositories": [
    {
        "type": "path",
        "url": "../packages/openrouter-laravel",
        // Copy instead of symlink. A symlink pointing outside the project root
        // breaks inside Docker containers and on deploy, where the sibling
        // directory does not exist.
        "options": { "symlink": false }
    }
]
```

**From a Git remote** (once you host it — fill in your own URL):

```jsonc
"repositories": [
    { "type": "vcs", "url": "<your-git-remote>" }
]
```

Then:

```bash
composer require muni-graneros/openrouter-laravel
```

The service provider and the `OpenRouter` facade are registered automatically
through Laravel's package discovery. Nothing to add to `bootstrap/providers.php`.

## Configuration

Set your key and you are done:

```dotenv
OPENROUTER_KEY=sk-or-v1-...
```

To change any default, publish the config file:

```bash
php artisan vendor:publish --tag=openrouter-config
```

That writes `config/openrouter.php`. Every key can also come from the environment:

| Variable | Default | What it does |
|---|---|---|
| `OPENROUTER_KEY` | *(none)* | API key from <https://openrouter.ai/keys>. Without it the client throws instead of sending an unauthenticated request. |
| `OPENROUTER_BASE_URL` | `https://openrouter.ai/api/v1` | Endpoint. Change it only to point at a proxy or a mock. |
| `OPENROUTER_DEFAULT_MODEL` | `openrouter/free` | Model used when a call does not name one. |
| `OPENROUTER_FALLBACK_MODEL` | *(none)* | Paid model used **only** when a free model answers `429`. Empty means a rate-limited call just fails. Setting it spends money, so it is opt-in. |
| `OPENROUTER_EXCLUDE_LOGGING` | `false` | `true` routes only to providers that neither train on nor retain the prompt (`provider.data_collection=deny` plus `provider.zdr=true`). |

`timeout` (60 seconds) lives in the published config file rather than the
environment. It covers the whole request — including the full duration of a
`stream()` — so raise it for long generations.

## Usage

Inject the client anywhere:

```php
use Muni\OpenRouter\OpenRouterClient;

class SummarizeDocument
{
    public function __construct(private readonly OpenRouterClient $openRouter) {}

    public function __invoke(string $text): string
    {
        return $this->openRouter->chat([
            ['role' => 'system', 'content' => 'Summarize in two sentences. Answer in Spanish.'],
            ['role' => 'user', 'content' => $text],
        ]);
    }
}
```

Or through the facade:

```php
use Muni\OpenRouter\Facades\OpenRouter;

$text = OpenRouter::chat([
    ['role' => 'user', 'content' => 'What is an ordinance?'],
]);
```

### Choosing a model and passing options per call

Anything you pass in `$options` goes straight into the request body, so every
OpenAI-compatible parameter works. `model` is the one key the client consumes
itself:

```php
$text = OpenRouter::chat($messages, [
    'model' => 'anthropic/claude-sonnet-4.5',
    'temperature' => 0.2,
    'max_tokens' => 500,
]);
```

### The full response

`raw()` returns the decoded payload, for when `usage` or `finish_reason` matters:

```php
$payload = OpenRouter::raw($messages);

$text = $payload['choices'][0]['message']['content'];
$spent = $payload['usage']['total_tokens'];
```

### Streaming

`stream()` returns a generator that yields tokens as they arrive:

```php
return response()->stream(function () use ($messages) {
    foreach (OpenRouter::stream($messages) as $token) {
        echo 'data: '.json_encode(['token' => $token])."\n\n";
        ob_flush();
        flush();
    }
}, headers: ['Content-Type' => 'text/event-stream']);
```

There is no fallback model while streaming: once the first token is out there is
nothing left to fall back to, so a stream that starts on a rate-limited model
simply fails.

## Errors

Everything that cannot be recovered from throws `Muni\OpenRouter\OpenRouterException`,
carrying the upstream status and message:

```php
use Muni\OpenRouter\OpenRouterException;

try {
    $text = OpenRouter::chat($messages);
} catch (OpenRouterException $e) {
    // 429 with no fallback configured, 4xx, 5xx, or 0 when the request never
    // reached OpenRouter after its retries.
    report($e);

    $status = $e->status();
    $model = $e->model();
}
```

Transient network failures (DNS, refused connection, timeout) are retried three
times, 250 ms apart, before that exception is thrown. Responses are never retried
blindly: a `429` is handled by the fallback model instead, and hammering a
rate-limited endpoint would only make it worse.

## A note on privacy

Everything in `$messages` leaves your server and reaches a third party.

`OPENROUTER_EXCLUDE_LOGGING=true` restricts *routing* to providers that do not
train on or retain prompts. It is not a lawful basis for the transfer. Under
Chile's Ley 21.719, sending personal data to an external processor needs its own
justification, minimization, and traceability — the flag helps, it does not
decide for you.

## Testing

```bash
composer install
./vendor/bin/pest
```

The suite runs on Orchestra Testbench, so it needs no host application, and it
never reaches the network: every call is faked.

## License

MIT. See [LICENSE](LICENSE).
