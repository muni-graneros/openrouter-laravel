<?php

namespace Muni\OpenRouter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A call to OpenRouter that could not be completed.
 *
 * Carries the upstream HTTP status and the message OpenRouter returned, so the
 * caller can tell "the model refused" (4xx) apart from "the provider is down"
 * (5xx) or "we never reached the network" (status 0) without re-parsing the
 * response body.
 */
class OpenRouterException extends RuntimeException
{
    /**
     * @param  int  $status  Upstream HTTP status, or 0 when the request never got a response.
     * @param  string  $model  Model the failed call was routed to.
     */
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $model = '',
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Build the exception from a failed OpenRouter response.
     *
     * OpenRouter reports errors as {"error": {"message": "...", "code": 429}}.
     * When the body is not JSON (a gateway HTML page, for instance) the raw body
     * is used, truncated so a stray error page does not flood the log.
     */
    public static function fromResponse(Response $response, string $model): self
    {
        $message = $response->json('error.message');

        if (! is_string($message) || $message === '') {
            $message = trim($response->body());
        }

        if ($message === '') {
            $message = 'OpenRouter returned an empty error body.';
        }

        return new self(
            sprintf(
                'OpenRouter request for model [%s] failed with status %d: %s',
                $model,
                $response->status(),
                mb_strimwidth($message, 0, 500, '…'),
            ),
            $response->status(),
            $model,
        );
    }

    /**
     * Build the exception when the request never reached OpenRouter.
     *
     * Raised after the retries are exhausted: DNS failure, refused connection or
     * a timeout. Status is 0 because there is no upstream status to report.
     */
    public static function fromConnectionFailure(ConnectionException $exception, string $model): self
    {
        return new self(
            sprintf(
                'Could not reach OpenRouter for model [%s]: %s',
                $model,
                $exception->getMessage(),
            ),
            0,
            $model,
        );
    }

    /**
     * Build the exception when no API key is configured.
     */
    public static function missingApiKey(): self
    {
        return new self('OpenRouter is not configured: set OPENROUTER_KEY in the environment.');
    }

    /**
     * Upstream HTTP status, or 0 when the request never got a response.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Model the failed call was routed to.
     */
    public function model(): string
    {
        return $this->model;
    }
}
