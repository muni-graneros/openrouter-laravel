<?php

/*
|--------------------------------------------------------------------------
| OpenRouter
|--------------------------------------------------------------------------
|
| One gateway to many LLM providers, OpenAI-compatible.
|
| Publish this file to override any of it:
|
|     php artisan vendor:publish --tag=openrouter-config
|
*/

return [

    /*
    | API key, from https://openrouter.ai/keys.
    |
    | Without it the client refuses to send anything: a missing key should fail
    | loudly at the first call, not turn into an unauthenticated request against
    | a third party.
    */
    'key' => env('OPENROUTER_KEY'),

    /*
    | Endpoint. Only change it to point at a proxy or a mock.
    */
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    /*
    | Model used when a call does not name one. "openrouter/free" routes across
    | whatever free capacity exists, which is enough for non-critical work.
    */
    'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openrouter/free'),

    /*
    | Paid model used ONLY when a free one answers 429 (rate limited). Null means
    | no fallback: a rate-limited call just fails. Setting it spends money, so it
    | is opt-in.
    */
    'fallback_model' => env('OPENROUTER_FALLBACK_MODEL'),

    /*
    | When true, requests are routed only to providers that neither train on nor
    | retain the prompt (OpenRouter's data_collection=deny plus zdr).
    |
    | It is a routing constraint, not a lawful basis: whatever goes into a prompt
    | still leaves the server. Under Chile's Ley 21.719 the transfer needs its own
    | justification.
    */
    'exclude_logging' => (bool) env('OPENROUTER_EXCLUDE_LOGGING', false),

    /*
    | Seconds a request may take. Covers the whole stream in stream() too, not
    | just the connection, so long generations need it raised.
    */
    'timeout' => 60,

];
