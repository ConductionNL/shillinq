<?php

/**
 * HTTP adapter core for the pipelinq customer bridge.
 *
 * Slice 02 of the `bookings-pipelinq-customer-bridge` chain (ADR-032). This
 * is the *transport* layer: the resilient HTTP client that every later slice
 * (contact read, klantbeeld read, timeline publish, lifecycle events) uses
 * to talk to a remote pipelinq deployment. The adapter wraps Nextcloud's
 * `IClientService` (ADR-003, stack-consistent over raw Guzzle), reads its
 * endpoint and bearer token from `IAppConfig` (member 01 keys), enforces
 * the shared retry policy (1s/2s/4s, max 3 attempts) and the shared circuit
 * breaker (5 consecutive failures → open for 5 minutes), and surfaces every
 * failure as a {@see PipelinqTransportException} with the safe-to-log
 * message + status code.
 *
 * No request methods are implemented here — `fetchContact()` and
 * `fetchKlantbeeld()` land in slices 03/04, `publishTimelineEntry()` in
 * slice 07. The single `request()` entrypoint is `protected` so those
 * slices can extend it via the cleanest possible seam, while this slice
 * stays focused on the cross-cutting concerns (auth, retries, breaker,
 * CloudEvents-compatible JSON shape, logging).
 *
 * Security (ADR-005):
 *   - The bearer token is read from IAppConfig and sent only as an
 *     `Authorization: Bearer …` header. It is NEVER written to logs,
 *     exception messages, or response bodies.
 *   - Log lines redact credentials and only carry the endpoint host + path,
 *     attempt number, status code, and circuit-breaker state.
 *
 * Spec deltas added by this slice:
 *   - "The adapter SHALL provide a resilient HTTP transport with bounded retries"
 *   - "The adapter SHALL fail fast via a circuit breaker"
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use OCA\Shillinq\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * Resilient HTTP transport for pipelinq calls.
 *
 * Member 02 of 11 in the customer-bridge chain. Provides the shared
 * request loop; concrete read/write methods land in members 03/04/07.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
class PipelinqContactAdapter
{
    /**
     * @var int Per-request HTTP timeout in seconds (giant decision D5).
     */
    public const REQUEST_TIMEOUT_SECONDS = 3;

    /**
     * @var string IAppConfig key for the pipelinq endpoint (slice 01).
     */
    public const CONFIG_KEY_ENDPOINT = 'pipelinq_endpoint';

    /**
     * @var string IAppConfig key for the pipelinq bearer token (slice 01).
     */
    public const CONFIG_KEY_TOKEN = 'pipelinq_token';

    /**
     * @var IClient|null Lazily-built HTTP client (one per adapter instance).
     */
    private ?IClient $client = null;

    /**
     * @var CircuitBreaker Shared circuit breaker.
     */
    private readonly CircuitBreaker $circuitBreaker;

    /**
     * Constructor.
     *
     * @param IClientService     $clientService HTTP transport factory (NC `IHTTPClientService`).
     * @param IAppConfig         $appConfig     App-scoped config; reads endpoint + token from slice 01.
     * @param LoggerInterface    $logger        PSR logger; transport failures logged at WARNING.
     * @param ICache             $cache         Cache layer (in-memory or distributed); used by slice 03 for contact TTL caching.
     * @param RetryPolicy|null   $retryPolicy   Override the default retry policy (exposed for tests).
     * @param CircuitBreaker|null $breaker      Override the default circuit breaker (exposed for tests).
     * @param \Closure|null      $sleeper       Callable that pauses for N seconds; injected so tests can run without real delays.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly ICache $cache,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        ?CircuitBreaker $breaker = null,
        private readonly ?\Closure $sleeper = null
    ) {
        // Wire the breaker's WARNING-level transition log here so the
        // CircuitBreaker stays pure and unit-testable.
        $this->circuitBreaker = ($breaker ?? new CircuitBreaker(
            failureThreshold: 5,
            cooldownSeconds: 300,
            clock: null,
            onTransition: function (string $from, string $to, string $reason): void {
                $this->logger->warning(
                    'pipelinq circuit breaker transitioned',
                    [
                        'app'    => Application::APP_ID,
                        'from'   => $from,
                        'to'     => $to,
                        'reason' => $reason,
                    ]
                );
            }
        ));

    }//end __construct()

    /**
     * Expose the cache layer to slices that perform read-through caching.
     *
     * Slice 03 (`PipelinqContactReader`) wraps `fetchContact()` calls in a
     * short TTL keyed on the pipelinq contact ID; that consumer needs the
     * same `ICache` instance the adapter holds.
     *
     * @return ICache
     */
    public function cache(): ICache
    {
        return $this->cache;

    }//end cache()

    /**
     * Inspect the current breaker state (exposed for tests + observability).
     *
     * @return string One of CircuitBreaker::STATE_CLOSED/OPEN/HALF_OPEN.
     */
    public function circuitState(): string
    {
        return $this->circuitBreaker->state();

    }//end circuitState()

    /**
     * Issue an authenticated request to pipelinq with retry + breaker.
     *
     * Returns the decoded JSON body on success. On non-transient or
     * retry-exhausted failure raises {@see PipelinqTransportException};
     * when the circuit breaker is open, fails fast WITHOUT issuing the
     * request.
     *
     * The body envelope is the pipelinq CloudEvents-compatible JSON shape:
     * the caller passes the `data` payload (or NULL for GETs) and the
     * adapter handles serialization, the `Content-Type: application/json`
     * header, and the `Authorization: Bearer …` header.
     *
     * @param string                    $method  HTTP method (GET / POST / PUT / DELETE).
     * @param string                    $path    Path relative to the endpoint, e.g. `/contacts/{id}`.
     * @param array<string, mixed>|null $payload Optional JSON-serialisable body for non-GET methods.
     *
     * @return array<string, mixed> Decoded JSON response body (empty array when the response has no content).
     *
     * @throws PipelinqTransportException When the breaker is open, the retry budget is exhausted, or the remote returns a non-retryable error.
     */
    protected function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->circuitBreaker->allowRequest() === false) {
            $this->logger->warning(
                'pipelinq request short-circuited (breaker open)',
                [
                    'app'    => Application::APP_ID,
                    'method' => $method,
                    'path'   => $path,
                ]
            );
            throw new PipelinqTransportException('pipelinq circuit breaker open; failing fast', 0);
        }

        $endpoint = $this->resolveEndpoint();
        $url      = $this->joinUrl($endpoint, $path);
        $options  = $this->buildOptions($payload);

        $attempt        = 0;
        $lastException  = null;
        $lastStatusCode = 0;

        while ($attempt < RetryPolicy::MAX_ATTEMPTS) {
            $attempt += 1;
            $transient = false;

            try {
                $response = $this->dispatch($method, $url, $options);
                $status   = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $this->circuitBreaker->recordSuccess();
                    $this->logger->debug(
                        'pipelinq request succeeded',
                        [
                            'app'     => Application::APP_ID,
                            'method'  => $method,
                            'path'    => $path,
                            'attempt' => $attempt,
                            'status'  => $status,
                        ]
                    );
                    return $this->decodeBody($response);
                }

                $lastStatusCode = $status;
                $transient      = $this->retryPolicy->isTransientStatus($status);
                $lastException  = new PipelinqTransportException(
                    sprintf('pipelinq request failed with HTTP %d', $status),
                    $status
                );
            } catch (PipelinqTransportException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Network-level failure (DNS, connect, timeout). Always transient.
                $transient      = true;
                $lastStatusCode = 0;
                $lastException  = new PipelinqTransportException(
                    'pipelinq request failed at transport layer',
                    0,
                    $e
                );
            }//end try

            $this->logger->warning(
                'pipelinq request attempt failed',
                [
                    'app'       => Application::APP_ID,
                    'method'    => $method,
                    'path'      => $path,
                    'attempt'   => $attempt,
                    'status'    => $lastStatusCode,
                    'transient' => $transient,
                ]
            );

            if ($this->retryPolicy->shouldRetry($attempt, $transient) === false) {
                $this->circuitBreaker->recordFailure();
                throw $lastException ?? new PipelinqTransportException('pipelinq request failed', $lastStatusCode);
            }

            $this->sleep($this->retryPolicy->backoffSeconds($attempt));
        }//end while

        $this->circuitBreaker->recordFailure();
        throw $lastException ?? new PipelinqTransportException('pipelinq retry budget exhausted', $lastStatusCode);

    }//end request()

    /**
     * Dispatch one HTTP request via the injected client service.
     *
     * Broken out so tests can override transport without re-implementing
     * the retry loop.
     *
     * @param string               $method  HTTP method.
     * @param string               $url     Fully qualified URL.
     * @param array<string, mixed> $options Guzzle-shaped options accepted by IClient.
     *
     * @return IResponse
     */
    protected function dispatch(string $method, string $url, array $options): IResponse
    {
        $client = $this->httpClient();
        $verb   = strtoupper($method);

        return match ($verb) {
            'GET'    => $client->get($url, $options),
            'POST'   => $client->post($url, $options),
            'PUT'    => $client->put($url, $options),
            'DELETE' => $client->delete($url, $options),
            default  => throw new PipelinqTransportException(
                sprintf('unsupported HTTP method %s', $verb),
                0
            ),
        };

    }//end dispatch()

    /**
     * Lazily build (and memoise) the underlying NC HTTP client.
     *
     * @return IClient
     */
    private function httpClient(): IClient
    {
        if ($this->client === null) {
            $this->client = $this->clientService->newClient();
        }

        return $this->client;

    }//end httpClient()

    /**
     * Read the pipelinq endpoint from IAppConfig (slice 01 key).
     *
     * @return string Endpoint URL without a trailing slash.
     *
     * @throws PipelinqTransportException When the endpoint is missing.
     */
    private function resolveEndpoint(): string
    {
        $endpoint = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ENDPOINT, ''));
        if ($endpoint === '') {
            throw new PipelinqTransportException('pipelinq endpoint is not configured', 0);
        }

        return rtrim($endpoint, '/');

    }//end resolveEndpoint()

    /**
     * Read the pipelinq bearer token from IAppConfig (slice 01 key).
     *
     * @return string Token; empty string when missing — `buildOptions()`
     *                detects this and surfaces a TransportException so the
     *                caller never accidentally issues an unauthenticated
     *                request.
     */
    private function resolveToken(): string
    {
        return trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_TOKEN, ''));

    }//end resolveToken()

    /**
     * Build the IClient request options (timeout, JSON body, auth header).
     *
     * @param array<string, mixed>|null $payload Optional JSON body.
     *
     * @return array<string, mixed>
     *
     * @throws PipelinqTransportException When the bearer token is missing.
     */
    private function buildOptions(?array $payload): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            throw new PipelinqTransportException('pipelinq token is not configured', 0);
        }

        $options = [
            'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
            'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
            'headers'         => [
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'shillinq/pipelinq-adapter',
            ],
        ];

        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $encoded;
        }

        return $options;

    }//end buildOptions()

    /**
     * Decode the response body as JSON; tolerate an empty 204-style body.
     *
     * @param IResponse $response Response from the HTTP client.
     *
     * @return array<string, mixed>
     *
     * @throws PipelinqTransportException When the body is not valid JSON.
     */
    private function decodeBody(IResponse $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PipelinqTransportException('pipelinq response is not valid JSON', $response->getStatusCode(), $e);
        }

        if (is_array($decoded) === false) {
            throw new PipelinqTransportException('pipelinq response root must be a JSON object', $response->getStatusCode());
        }

        return $decoded;

    }//end decodeBody()

    /**
     * Concatenate the endpoint and a path, normalising the slash.
     *
     * @param string $endpoint Endpoint URL (no trailing slash).
     * @param string $path     Resource path (with or without a leading slash).
     *
     * @return string
     */
    private function joinUrl(string $endpoint, string $path): string
    {
        if ($path === '') {
            return $endpoint;
        }

        if (str_starts_with($path, '/') === true) {
            return $endpoint.$path;
        }

        return $endpoint.'/'.$path;

    }//end joinUrl()

    /**
     * Pause between retry attempts using the injected sleeper, or PHP `sleep()`.
     *
     * @param int $seconds Seconds to wait.
     *
     * @return void
     */
    private function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }

        sleep($seconds);

    }//end sleep()
}//end class
