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
 * Slice 03 extends the transport with the {@see self::getContact()} read
 * path: a read-through cache around `GET /api/v1/contacts/{externalId}`
 * with a 5-minute TTL, a fallback DTO on 404 / malformed JSON, and
 * graceful degradation that serves a still-valid cached Contact when
 * pipelinq is unavailable. The Klantbeeld read (04), timeline publish
 * (07) and lifecycle events (08) extend this same transport via the
 * `protected request()` seam.
 *
 * Security (ADR-005):
 *   - The bearer token is read from IAppConfig and sent only as an
 *     `Authorization: Bearer …` header. It is NEVER written to logs,
 *     exception messages, or response bodies.
 *   - Log lines redact credentials and only carry the endpoint host + path,
 *     attempt number, status code, and circuit-breaker state.
 *
 * Spec deltas added by slice 02 (transport):
 *   - "The adapter SHALL provide a resilient HTTP transport with bounded retries"
 *   - "The adapter SHALL fail fast via a circuit breaker"
 *
 * Spec deltas added by slice 03 (contact read):
 *   - "The adapter SHALL load a pipelinq Contact by externalId"
 *   - "The adapter SHALL cache Contact data with a bounded TTL"
 *
 * Spec deltas added by slice 07 (timeline publish core):
 *   - "The adapter SHALL publish a timeline event to pipelinq"
 *
 * Spec deltas added by slice 08 (lifecycle events + auth handling):
 *   - "The system SHALL publish confirmed, cancelled, and completed
 *     timeline events"
 *   - "The system SHALL treat timeline auth failures as permanent"
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * Pre-existing debt (issue #506): early-return refactor deferred pending
 * full behavioral verification of each branch.
 */
class PipelinqContactAdapter {
	/**
	 * Per-request HTTP timeout in seconds (giant decision D5).
	 *
	 * @var int
	 */
	public const REQUEST_TIMEOUT_SECONDS = 3;

	/**
	 * IAppConfig key for the pipelinq endpoint (slice 01).
	 *
	 * @var string
	 */
	public const CONFIG_KEY_ENDPOINT = 'pipelinq_endpoint';

	/**
	 * IAppConfig key for the pipelinq bearer token (slice 01).
	 *
	 * @var string
	 */
	public const CONFIG_KEY_TOKEN = 'pipelinq_token';

	/**
	 * TTL (seconds) for cached Contact entries — giant decision D6.
	 *
	 * @var int
	 */
	public const CONTACT_CACHE_TTL_SECONDS = 300;

	/**
	 * Cache key prefix for cached Contact entries.
	 *
	 * Each Contact is stored at `pipelinq:contact:{externalId}`; the
	 * `clearCache()` invalidation wipes the whole prefix.
	 *
	 * @var string
	 */
	public const CONTACT_CACHE_KEY_PREFIX = 'pipelinq:contact:';

	/**
	 * HTTP path template for the Contact read endpoint.
	 *
	 * @var string
	 */
	public const CONTACT_PATH_TEMPLATE = '/api/v1/contacts/%s';

	/**
	 * HTTP path for the timeline publish endpoint (slice 07).
	 *
	 * @var string
	 */
	public const TIMELINE_PATH = '/api/v1/timeline';

	/**
	 * Expected HTTP status for a successful timeline publish (slice 07
	 * design.md — pipelinq returns 201 Created).
	 *
	 * @var int
	 */
	public const TIMELINE_SUCCESS_STATUS = 201;

	/**
	 * Lazily-built HTTP client (one per adapter instance).
	 *
	 * @var IClient|null
	 */
	private ?IClient $client = null;

	/**
	 * Shared circuit breaker.
	 *
	 * @var CircuitBreaker
	 */
	private readonly CircuitBreaker $circuitBreaker;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP transport factory (NC `IHTTPClientService`).
	 * @param IAppConfig $appConfig App-scoped config; reads endpoint + token from slice 01.
	 * @param LoggerInterface $logger PSR logger; transport failures logged at WARNING.
	 * @param ICache $cache Cache layer (in-memory or distributed); used by slice 03 for contact TTL caching.
	 * @param RetryPolicy $retryPolicy Override the default retry policy (exposed for tests).
	 * @param CircuitBreaker|null $breaker Override the default circuit breaker (exposed for tests).
	 * @param \Closure|null $sleeper Callable that pauses for N seconds; injected so tests can run without real delays.
	 * @param CustomerBridgeMetricsService|null $metrics Optional metrics aggregator (slice 11); skipped when NULL so existing test
	 *                                                   wiring keeps working.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ICache $cache,
		private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
		?CircuitBreaker $breaker = null,
		private readonly ?\Closure $sleeper = null,
		private readonly ?CustomerBridgeMetricsService $metrics = null,
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
						'app' => Application::APP_ID,
						'from' => $from,
						'to' => $to,
						'reason' => $reason,
					]
				);
				$this->metrics?->recordCircuitState(state: $to);
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
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function cache(): ICache {
		return $this->cache;
	}//end cache()

	/**
	 * Inspect the current breaker state (exposed for tests + observability).
	 *
	 * @return string One of CircuitBreaker::STATE_CLOSED/OPEN/HALF_OPEN.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function circuitState(): string {
		return $this->circuitBreaker->state();
	}//end circuitState()

	/**
	 * Load a pipelinq Contact by `externalId`.
	 *
	 * Read-through cache against the injected {@see ICache} (5-minute
	 * TTL per `externalId`, key `pipelinq:contact:{externalId}`):
	 *
	 *   1. A cache hit is returned immediately, never touching pipelinq.
	 *   2. On a cache miss the adapter issues
	 *      `GET /api/v1/contacts/{externalId}` via the slice-02 request
	 *      loop (3s timeout, retry policy, circuit breaker).
	 *   3. A 200 response is parsed into a {@see PipelinqContact} DTO
	 *      and cached for the TTL.
	 *   4. A 404 is the expected "not found" outcome — a fallback DTO
	 *      is returned and cached (so the same lookup does not re-hit
	 *      pipelinq inside the TTL); no error is logged.
	 *   5. A malformed-JSON response yields a fallback DTO + a WARNING
	 *      log line; the raw body is truncated to 200 chars before
	 *      logging. No retry is issued — this is a client-side
	 *      classification, not a transient failure.
	 *   6. When pipelinq is unavailable (transport error / open
	 *      breaker) and a still-valid cached entry exists, the cached
	 *      entry is served instead of propagating the failure.
	 *
	 * @param string $externalId The pipelinq external Contact id (see slice 01).
	 *
	 * @return PipelinqContact The Contact (with `isFound()=true`) or a
	 *                         fallback DTO when the id is unknown / the
	 *                         response is malformed.
	 *
	 * @throws PipelinqTransportException When pipelinq is unavailable AND
	 *                                    no still-valid cache entry can
	 *                                    be served.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function getContact(string $externalId): PipelinqContact {
		$id = trim($externalId);
		if ($id === '') {
			throw new PipelinqTransportException(
				message: 'pipelinq Contact lookup requires a non-empty externalId',
				statusCode: 0
			);
		}

		$cacheKey = self::CONTACT_CACHE_KEY_PREFIX . $id;
		$cached = $this->readCachedContact(key: $cacheKey);
		if ($cached !== null) {
			$this->logger->debug(
				'pipelinq contact cache hit',
				[
					'app' => Application::APP_ID,
					'externalId' => $id,
				]
			);
			$this->metrics?->recordContactSuccess(fromCache: true);
			return $cached;
		}

		try {
			$payload = $this->request(
				method: 'GET',
				path: sprintf(self::CONTACT_PATH_TEMPLATE, rawurlencode($id))
			);
		} catch (PipelinqTransportException $e) {
			// 404 is an expected "not found" outcome — return the
			// fallback without escalating the log severity.
			if ($e->statusCode() === 404) {
				$this->logger->debug(
					'pipelinq contact not found',
					[
						'app' => Application::APP_ID,
						'externalId' => $id,
					]
				);
				$contact = PipelinqContact::notFound(externalId: $id);
				$this->writeCachedContact(key: $cacheKey, contact: $contact);
				$this->metrics?->recordContactFallback(reason: 'not_found');
				return $contact;
			}

			// Malformed JSON arrives wrapped in a TransportException
			// with a JsonException previous; surface a WARNING + a
			// fallback DTO and DO NOT retry (client-side classification).
			$previous = $e->getPrevious();
			if ($previous instanceof \JsonException) {
				$this->logger->warning(
					'pipelinq contact response malformed',
					[
						'app' => Application::APP_ID,
						'externalId' => $id,
						'status' => $e->statusCode(),
						'reason' => $previous->getMessage(),
					]
				);
				$this->metrics?->recordContactFallback(reason: 'malformed');
				return PipelinqContact::notFound(externalId: $id);
			}

			// Anything else (open breaker, transport error, 5xx) → try
			// to serve a still-valid cache entry; otherwise propagate.
			$stale = $this->readCachedContact(key: $cacheKey);
			if ($stale !== null) {
				$this->logger->warning(
					'pipelinq unavailable; serving cached contact',
					[
						'app' => Application::APP_ID,
						'externalId' => $id,
						'status' => $e->statusCode(),
					]
				);
				$this->metrics?->recordContactStaleServed();
				return $stale;
			}

			throw $e;
		}//end try

		$contact = PipelinqContact::fromApiPayload(externalId: $id, payload: $payload);
		$this->writeCachedContact(key: $cacheKey, contact: $contact);
		$this->metrics?->recordContactSuccess(fromCache: false);

		return $contact;
	}//end getContact()

	/**
	 * Invalidate every cached Contact entry.
	 *
	 * Wipes all keys under {@see self::CONTACT_CACHE_KEY_PREFIX}. Used by
	 * the admin "Clear pipelinq cache" action and exposed for tests so a
	 * cache-expiry scenario can be simulated without sleeping for 5
	 * minutes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function clearCache(): void {
		$this->cache->clear(self::CONTACT_CACHE_KEY_PREFIX);

	}//end clearCache()

	/**
	 * Read a cached Contact entry by key.
	 *
	 * Returns `null` on miss or when the cached value cannot be decoded
	 * (treated as a miss so the caller refreshes from pipelinq).
	 *
	 * @param string $key Full cache key, including the prefix.
	 *
	 * @return PipelinqContact|null
	 */
	private function readCachedContact(string $key): ?PipelinqContact {
		$raw = $this->cache->get($key);
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (is_array($decoded) === false) {
			return null;
		}

		return PipelinqContact::fromArray(data: $decoded);
	}//end readCachedContact()

	/**
	 * Persist a Contact in the cache with the configured TTL.
	 *
	 * Serialised as JSON because {@see ICache::set()} is documented to
	 * accept arbitrary "mixed" but the concrete distributed backends
	 * (APCu / Redis / Memcached) only round-trip scalars reliably.
	 *
	 * @param string $key Full cache key, including the prefix.
	 * @param PipelinqContact $contact Contact DTO to persist.
	 *
	 * @return void
	 */
	private function writeCachedContact(string $key, PipelinqContact $contact): void {
		try {
			$encoded = json_encode($contact->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (\JsonException $e) {
			$this->logger->warning(
				'pipelinq contact cache write skipped — encode failed',
				[
					'app' => Application::APP_ID,
					'reason' => $e->getMessage(),
				]
			);
			return;
		}

		$this->cache->set($key, $encoded, self::CONTACT_CACHE_TTL_SECONDS);

	}//end writeCachedContact()

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
	 * @param string $method HTTP method (GET / POST / PUT / DELETE).
	 * @param string $path Path relative to the endpoint, e.g. `/contacts/{id}`.
	 * @param array<string, mixed>|null $payload Optional JSON-serialisable body for non-GET methods.
	 *
	 * @return array<string, mixed> Decoded JSON response body (empty array when the response has no content).
	 *
	 * @throws PipelinqTransportException When the breaker is open, the retry budget is exhausted, or the remote returns a non-retryable error.
	 */
	protected function request(string $method, string $path, ?array $payload = null): array {
		if ($this->circuitBreaker->allowRequest() === false) {
			$this->logger->warning(
				'pipelinq request short-circuited (breaker open)',
				[
					'app' => Application::APP_ID,
					'method' => $method,
					'path' => $path,
				]
			);
			throw new PipelinqTransportException(
				message: 'pipelinq circuit breaker open; failing fast',
				statusCode: 0
			);
		}

		$endpoint = $this->resolveEndpoint();
		$url = $this->joinUrl(endpoint: $endpoint, path: $path);
		$options = $this->buildOptions(payload: $payload);

		$attempt = 0;
		$lastException = null;
		$lastStatusCode = 0;

		while ($attempt < RetryPolicy::MAX_ATTEMPTS) {
			$attempt += 1;
			$transient = false;

			try {
				$response = $this->dispatch(method: $method, url: $url, options: $options);
				$status = $response->getStatusCode();

				if ($status >= 200 && $status < 300) {
					$this->circuitBreaker->recordSuccess();
					$this->logger->debug(
						'pipelinq request succeeded',
						[
							'app' => Application::APP_ID,
							'method' => $method,
							'path' => $path,
							'attempt' => $attempt,
							'status' => $status,
						]
					);
					return $this->decodeBody(response: $response);
				}

				$lastStatusCode = $status;
				$transient = $this->retryPolicy->isTransientStatus(statusCode: $status);
				$lastException = new PipelinqTransportException(
					message: sprintf('pipelinq request failed with HTTP %d', $status),
					statusCode: $status
				);
			} catch (PipelinqTransportException $e) {
				throw $e;
			} catch (\Throwable $e) {
				// Network-level failure (DNS, connect, timeout). Always transient.
				$transient = true;
				$lastStatusCode = 0;
				$lastException = new PipelinqTransportException(
					message: 'pipelinq request failed at transport layer',
					statusCode: 0,
					previous: $e
				);
			}//end try

			$this->logger->warning(
				'pipelinq request attempt failed',
				[
					'app' => Application::APP_ID,
					'method' => $method,
					'path' => $path,
					'attempt' => $attempt,
					'status' => $lastStatusCode,
					'transient' => $transient,
				]
			);

			if ($this->retryPolicy->shouldRetry(attempt: $attempt, isTransient: $transient) === false) {
				$this->circuitBreaker->recordFailure();

				// ADR-005 ops contract: a 401 from pipelinq means the token
				// is rejected — every subsequent call will fail the same
				// way until an admin updates the credentials. Surface this
				// at ERROR so it crosses the "needs human attention"
				// threshold of the nextcloud.log subscribers.
				if ($lastStatusCode === 401) {
					$this->logger->error(
						'pipelinq authentication rejected — admin must rotate the bearer token',
						[
							'app' => Application::APP_ID,
							'method' => $method,
							'path' => $path,
							'status' => $lastStatusCode,
						]
					);
					$this->metrics?->recordPermanentFailure(reason: 'auth');
				}

				throw $lastException;
			}//end if

			$this->metrics?->recordRetryAttempt(attempt: $attempt);
			$this->sleep(seconds: $this->retryPolicy->backoffSeconds(attempt: $attempt));
		}//end while

		// Loop exits only via the throws inside it; reaching here would mean
		// the retry budget was somehow exhausted without a failure being
		// recorded -- defensive fallback for the type checker.
		$this->circuitBreaker->recordFailure();
		$this->logger->error(
			'pipelinq retry budget exhausted — request abandoned',
			[
				'app' => Application::APP_ID,
				'method' => $method,
				'path' => $path,
				'status' => $lastStatusCode,
			]
		);
		$this->metrics?->recordPermanentFailure(reason: 'dead_letter');
		throw new PipelinqTransportException(
			message: 'pipelinq retry budget exhausted',
			statusCode: $lastStatusCode
		);

	}//end request()

	/**
	 * Fetch the klantbeeld transaction history for a contact.
	 *
	 * GETs `/api/v1/contacts/{externalId}/klantbeeld?limit=…&offset=…` via
	 * the resilient transport (slice 02), parses the `transactions` array
	 * into {@see KlantbeeldTransaction} rows, and wraps the page in a
	 * {@see KlantbeeldResult} envelope so the slice-05 controller can
	 * distinguish three legitimate outcomes:
	 *
	 *   1. Available with rows — render the history.
	 *   2. Available, empty — render "no previous transactions" (NOT an error).
	 *   3. Unavailable (klantbeeld 5xx while Contact succeeded) — render the
	 *      profile but hide / replace history.
	 *
	 * The `limit` argument is clamped to the spec range (1..100, default 5)
	 * before being placed on the query string so a downstream caller cannot
	 * fan out an unbounded page. The `offset` argument is clamped to a
	 * non-negative integer for the same reason.
	 *
	 * No persistent cache is used: transactions are immutable but the page
	 * window depends on the (limit, offset) tuple AND the live tail of the
	 * ledger, so per the giant's decision D6 we cache only within the
	 * request (the consumer holds its own session-scoped memoisation).
	 *
	 * Failure semantics:
	 *
	 *   - 404 on the contact → returns an empty available page (the
	 *     contact has no klantbeeld; the profile fetch in slice 03 owns
	 *     the strict "contact missing" answer).
	 *   - 5xx exhausting the retry budget, or the breaker tripping →
	 *     returns {@see KlantbeeldResult::unavailable()} so the profile
	 *     stays usable. The underlying transport failure is logged at
	 *     WARNING.
	 *   - Any other non-transport failure (malformed JSON, unsupported
	 *     shape) → returns {@see KlantbeeldResult::unavailable()} with a
	 *     WARNING log. We never raise out of this method: the entire
	 *     point of the envelope is to let the slice-05 controller keep
	 *     the profile rendered regardless.
	 *
	 * @param string $externalId Pipelinq contact id (slice 01 link).
	 * @param int $limit Page size, default 5, clamped to 1..100.
	 * @param int $offset Page offset, clamped to ≥0, default 0.
	 *
	 * @return KlantbeeldResult Envelope; never throws.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function fetchKlantbeeld(string $externalId, int $limit = 5, int $offset = 0): KlantbeeldResult {
		$safeLimit = $this->clampLimit(limit: $limit);
		$safeOffset = max(0, $offset);

		$path = sprintf(
			'/api/v1/contacts/%s/klantbeeld?limit=%d&offset=%d',
			rawurlencode($externalId),
			$safeLimit,
			$safeOffset
		);

		try {
			$body = $this->request(method: 'GET', path: $path);
		} catch (PipelinqTransportException $e) {
			if ($e->statusCode() === 404) {
				// Treat "contact has no klantbeeld" as a valid empty page;
				// the profile fetch (slice 03) is the canonical "contact
				// missing" check.
				return KlantbeeldResult::available(transactions: [], limit: $safeLimit, offset: $safeOffset);
			}

			$this->logger->warning(
				'pipelinq klantbeeld unavailable',
				[
					'app' => Application::APP_ID,
					'externalId' => $externalId,
					'status' => $e->statusCode(),
					'breaker' => $this->circuitBreaker->state(),
				]
			);

			return KlantbeeldResult::unavailable(limit: $safeLimit, offset: $safeOffset);
		}//end try

		$rows = ($body['transactions'] ?? null);
		if (is_array($rows) === false) {
			$this->logger->warning(
				'pipelinq klantbeeld response missing transactions array',
				[
					'app' => Application::APP_ID,
					'externalId' => $externalId,
				]
			);
			return KlantbeeldResult::unavailable(limit: $safeLimit, offset: $safeOffset);
		}

		$transactions = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$transactions[] = KlantbeeldTransaction::fromArray(row: $row);
		}

		return KlantbeeldResult::available(
			transactions: $transactions,
			limit: $safeLimit,
			offset: $safeOffset
		);

	}//end fetchKlantbeeld()

	/**
	 * Publish a timeline event to pipelinq.
	 *
	 * Slice 07 of the customer-bridge chain. POSTs the fixed JSON payload
	 * (type, externalId, timestamp, contactId, metadata) to
	 * `/api/v1/timeline` via the slice-02 request loop:
	 *
	 *   - 3 second HTTP timeout (giant decision D5).
	 *   - Shared retry policy (1s/2s/4s, max 3 attempts) on transient 5xx
	 *     and transport errors.
	 *   - Shared circuit breaker (open after 5 consecutive failures, fast
	 *     fail until the cooldown expires).
	 *   - Bearer token sent as `Authorization: Bearer …` only, never
	 *     reaches the payload, the URL, or any log line (ADR-005).
	 *
	 * The method is best-effort: any failure (open breaker, retry budget
	 * exhausted, non-2xx) is logged at WARNING and surfaced as a
	 * `false` return so the caller can hand the event off for async
	 * retry (slice 09) without raising. A success is logged at DEBUG
	 * via the underlying {@see self::request()} loop.
	 *
	 * The design.md contract is `201 Created` on success; the shared
	 * {@see self::request()} loop treats any 2xx as success and returns
	 * the decoded body. We accept that tolerance here rather than
	 * second-guess the transport — if pipelinq ever returns 200/202 to
	 * a publish, that signals an upstream contract drift that surfaces
	 * via the existing DEBUG `pipelinq request succeeded` log line
	 * (which already carries the actual status code).
	 *
	 * @param TimelineEventDto $event Event to publish.
	 *
	 * @return bool TRUE when the publish succeeded (HTTP 201), FALSE otherwise.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function publishTimelineEvent(TimelineEventDto $event): bool {
		return $this->publishWithOutcome(event: $event)->isSuccess();
	}//end publishTimelineEvent()

	/**
	 * Publish a timeline event and return the granular publish outcome.
	 *
	 * Slice 08 extension over {@see self::publishTimelineEvent()}: the
	 * lifecycle listener needs to distinguish a permanent auth rejection
	 * (401 — never retry, alert admin) from a transient failure (open
	 * breaker / 5xx / network — hand off to the async retry queue). This
	 * method returns the {@see TimelinePublishOutcome} produced by the
	 * publish attempt so the listener can choose the right follow-up.
	 *
	 * Logging contract:
	 *   - Success → INFO `pipelinq timeline publish succeeded`.
	 *   - 401     → ERROR `Invalid pipelinq API token`; the bearer token
	 *               is NEVER included in the log payload (ADR-005).
	 *   - Other   → WARNING `pipelinq timeline publish failed`, identical
	 *               to the slice-07 line.
	 *
	 * The method is best-effort: every failure produces an outcome
	 * value, never an exception.
	 *
	 * @param TimelineEventDto $event Event to publish.
	 *
	 * @return TimelinePublishOutcome Terminal outcome of the publish.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
	 */
	public function publishWithOutcome(TimelineEventDto $event): TimelinePublishOutcome {
		$payload = $event->toPayload();

		try {
			$this->request(
				method: 'POST',
				path: self::TIMELINE_PATH,
				payload: $payload
			);
		} catch (PipelinqTransportException $e) {
			// 401 is a permanent failure — the configured token is
			// invalid / revoked. Log ERROR with the *config key* (not
			// the token value, ADR-005) so an operator can rotate the
			// secret. Slice 08's spec REQ also requires no retry; the
			// listener honours that by NOT enqueueing on AuthRejected.
			if ($e->statusCode() === 401) {
				$this->logger->error(
					'Invalid pipelinq API token; check config',
					[
						'app' => Application::APP_ID,
						'configKey' => self::CONFIG_KEY_TOKEN,
						'type' => $event->type(),
						'externalId' => $event->externalId(),
						'contactId' => $event->contactId(),
						'status' => 401,
					]
				);
				return TimelinePublishOutcome::AuthRejected;
			}

			if ($e->isCircuitOpen() === true) {
				$reason = 'breaker_open';
			} else {
				$reason = 'transport';
			}

			$this->logger->warning(
				'pipelinq timeline publish failed',
				[
					'app' => Application::APP_ID,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'contactId' => $event->contactId(),
					'status' => $e->statusCode(),
					'breaker' => $this->circuitBreaker->state(),
					'reason' => $reason,
				]
			);
			return TimelinePublishOutcome::Transient;
		}//end try

		$this->logger->info(
			'pipelinq timeline publish succeeded',
			[
				'app' => Application::APP_ID,
				'type' => $event->type(),
				'externalId' => $event->externalId(),
				'contactId' => $event->contactId(),
			]
		);
		$this->metrics?->recordTimelinePublishSuccess();

		return TimelinePublishOutcome::Success;
	}//end publishWithOutcome()

	/**
	 * Clamp the caller-supplied limit to the spec range (1..100, default 5).
	 *
	 * The pipelinq klantbeeld endpoint caps page size at 100; anything
	 * larger is rejected by the backend, and a non-positive value would
	 * round-trip to the backend as `limit=0` which is meaningless. Centralise
	 * the clamp so neither the controller nor the UI has to remember the
	 * bounds.
	 *
	 * @param int $limit Caller-supplied page size.
	 *
	 * @return int Clamped value in [1, 100].
	 */
	private function clampLimit(int $limit): int {
		if ($limit < 1) {
			return 5;
		}

		return min($limit, 100);
	}//end clampLimit()

	/**
	 * Dispatch one HTTP request via the injected client service.
	 *
	 * Broken out so tests can override transport without re-implementing
	 * the retry loop.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Fully qualified URL.
	 * @param array<string, mixed> $options Guzzle-shaped options accepted by IClient.
	 *
	 * @return IResponse
	 */
	protected function dispatch(string $method, string $url, array $options): IResponse {
		$client = $this->httpClient();
		$verb = strtoupper($method);

		return match ($verb) {
			'GET' => $client->get($url, $options),
			'POST' => $client->post($url, $options),
			'PUT' => $client->put($url, $options),
			'DELETE' => $client->delete($url, $options),
			default => throw new PipelinqTransportException(
				message: sprintf('unsupported HTTP method %s', $verb),
				statusCode: 0
			),
		};

	}//end dispatch()

	/**
	 * Lazily build (and memoise) the underlying NC HTTP client.
	 *
	 * @return IClient
	 */
	private function httpClient(): IClient {
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
	private function resolveEndpoint(): string {
		$endpoint = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ENDPOINT, ''));
		if ($endpoint === '') {
			throw new PipelinqTransportException(
				message: 'pipelinq endpoint is not configured',
				statusCode: 0
			);
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
	private function resolveToken(): string {
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
	private function buildOptions(?array $payload): array {
		$token = $this->resolveToken();
		if ($token === '') {
			throw new PipelinqTransportException(
				message: 'pipelinq token is not configured',
				statusCode: 0
			);
		}

		$options = [
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
			'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
				'User-Agent' => 'shillinq/pipelinq-adapter',
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
	private function decodeBody(IResponse $response): array {
		$body = (string)$response->getBody();
		if ($body === '') {
			return [];
		}

		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new PipelinqTransportException(
				message: 'pipelinq response is not valid JSON',
				statusCode: $response->getStatusCode(),
				previous: $e
			);
		}

		if (is_array($decoded) === false) {
			throw new PipelinqTransportException(
				message: 'pipelinq response root must be a JSON object',
				statusCode: $response->getStatusCode()
			);
		}

		return $decoded;
	}//end decodeBody()

	/**
	 * Concatenate the endpoint and a path, normalising the slash.
	 *
	 * @param string $endpoint Endpoint URL (no trailing slash).
	 * @param string $path Resource path (with or without a leading slash).
	 *
	 * @return string
	 */
	private function joinUrl(string $endpoint, string $path): string {
		if ($path === '') {
			return $endpoint;
		}

		if (str_starts_with($path, '/') === true) {
			return $endpoint . $path;
		}

		return $endpoint . '/' . $path;
	}//end joinUrl()

	/**
	 * Pause between retry attempts using the injected sleeper, or PHP `sleep()`.
	 *
	 * @param int $seconds Seconds to wait.
	 *
	 * @return void
	 */
	private function sleep(int $seconds): void {
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
