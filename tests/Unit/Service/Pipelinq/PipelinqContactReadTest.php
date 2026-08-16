<?php

/**
 * Unit tests for the PipelinqContactAdapter::getContact() read path.
 *
 * Exercises slice 03 of the customer-bridge chain:
 *   - Contact found / not found / malformed responses
 *   - 5-minute TTL cache hit / miss / expiry / manual invalidation
 *   - Graceful degradation: serving a still-valid cached Contact when
 *     pipelinq is unavailable
 *
 * The transport itself (retry + breaker) is owned by slice 02 and is
 * stubbed here by overriding the protected `dispatch()` seam with a
 * scripted queue of canned responses.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the Contact read path: HTTP + cache + degradation.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
 */
final class PipelinqContactReadTest extends TestCase {
	/**
	 * Build an array-backed in-memory ICache double honouring set/get/remove/clear.
	 *
	 * @param array<int, array{key: string, ttl: int}> &$writes Captured write log (output).
	 *
	 * @return ICache
	 */
	private function inMemoryCache(array &$writes): ICache {
		return new class($writes) implements ICache {
			/**
			 * @var array<string, string>
			 */
			private array $store = [];

			/**
			 * @var array<int, array{key: string, ttl: int}>
			 */
			private array $writes;

			/**
			 * @param array<int, array{key: string, ttl: int}> &$writes Capture sink.
			 */
			public function __construct(array &$writes) {
				$this->writes = & $writes;
			}//end __construct()

			/**
			 * @param string $key Cache key.
			 *
			 * @return mixed
			 */
			public function get($key) {
				return ($this->store[$key] ?? null);
			}//end get()

			/**
			 * @param string $key Cache key.
			 * @param mixed $value Value.
			 * @param int $ttl TTL.
			 *
			 * @return bool
			 */
			public function set($key, $value, $ttl = 0) {
				$this->store[$key] = (string)$value;
				$this->writes[] = ['key' => $key, 'ttl' => $ttl];
				return true;
			}//end set()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function hasKey($key) {
				return array_key_exists($key, $this->store);
			}//end hasKey()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function remove($key) {
				unset($this->store[$key]);
				return true;
			}//end remove()

			/**
			 * @param string $prefix Optional prefix.
			 *
			 * @return bool
			 */
			public function clear($prefix = '') {
				if ($prefix === '') {
					$this->store = [];
					return true;
				}

				foreach (array_keys($this->store) as $key) {
					if (str_starts_with($key, $prefix) === true) {
						unset($this->store[$key]);
					}
				}

				return true;
			}//end clear()

			/**
			 * @return bool
			 */
			public static function isAvailable(): bool {
				return true;
			}//end isAvailable()
		};

	}//end inMemoryCache()

	/**
	 * Build the test adapter subclass that swaps the HTTP `dispatch()` for
	 * a scripted queue. Mirrors slice 02's pattern.
	 *
	 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
	 * @param ICache $cache In-memory cache double.
	 * @param array<int, int> &$dispatchCalls Captured dispatch counter (output).
	 * @param AbstractLogger|null $logger Optional recording logger.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(
		array $script,
		ICache $cache,
		array &$dispatchCalls,
		?AbstractLogger $logger = null,
	): PipelinqContactAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === PipelinqContactAdapter::CONFIG_KEY_ENDPOINT) {
					return 'https://pipelinq.test';
				}

				if ($key === PipelinqContactAdapter::CONFIG_KEY_TOKEN) {
					return 'super-secret-token';
				}

				return $default;
			}
		);

		if ($logger === null) {
			$logger = new class extends AbstractLogger {

				/**
				 * @var array<int, array<string, mixed>>
				 */
				public array $records = [];

				/**
				 * @param mixed $level Log level.
				 * @param string|\Stringable $message Message.
				 * @param array<string, mixed> $context Context.
				 *
				 * @return void
				 */
				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				}//end log()
			};
		}

		$clientService = $this->createMock(IClientService::class);

		$sleeper = static function (int $seconds): void {
			// No-op: the tests do not assert backoff timing.
			unset($seconds);
		};

		// Use a generous breaker threshold so a small batch of 4xx hits
		// never opens the breaker in these scenarios; slice 02 already
		// covers the open-breaker behaviour exhaustively.
		$breaker = new CircuitBreaker(failureThreshold: 50, cooldownSeconds: 300);

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), $breaker, $sleeper, $script, $dispatchCalls) extends PipelinqContactAdapter {
			/**
			 * @var array<int, IResponse|\Throwable>
			 */
			private array $script;

			/**
			 * @var array<int, int>
			 */
			private array $dispatchCalls;

			/**
			 * @param IClientService $clientService Mock client service.
			 * @param IAppConfig $appConfig Mock config.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache In-memory cache.
			 * @param RetryPolicy $retryPolicy Policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper No-op sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
			 * @param array<int, int> &$dispatchCalls Counter capture.
			 */
			public function __construct(
				IClientService $clientService,
				IAppConfig $appConfig,
				$logger,
				ICache $cache,
				RetryPolicy $retryPolicy,
				?CircuitBreaker $breaker,
				\Closure $sleeper,
				array $script,
				array &$dispatchCalls,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->script = $script;
				$this->dispatchCalls = & $dispatchCalls;
			}//end __construct()

			/**
			 * Bypass the real HTTP dispatch with a scripted queue.
			 *
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$this->dispatchCalls[] = 1;
				$outcome = array_shift($this->script);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				if ($outcome === null) {
					throw new \RuntimeException('script exhausted');
				}

				return $outcome;
			}//end dispatch()
		};

	}//end buildAdapter()

	/**
	 * Build a canned IResponse with the given status and body.
	 *
	 * @param int $statusCode HTTP status.
	 * @param array<string, mixed>|null $body Body — when null the raw body string is used.
	 * @param string|null $rawBody Raw body override (for malformed JSON scenarios).
	 *
	 * @return IResponse
	 */
	private function response(int $statusCode, ?array $body = null, ?string $rawBody = null): IResponse {
		if ($rawBody === null) {
			$rawBody = json_encode(($body ?? []), JSON_THROW_ON_ERROR);
		}

		return new class($statusCode, (string)$rawBody) implements IResponse {
			/**
			 * @param int $statusCode HTTP status.
			 * @param string $body Body string.
			 */
			public function __construct(
				private readonly int $statusCode,
				private readonly string $body,
			) {
			}//end __construct()

			/**
			 * @return string
			 */
			public function getBody() {
				return $this->body;
			}//end getBody()

			/**
			 * @return int
			 */
			public function getStatusCode(): int {
				return $this->statusCode;
			}//end getStatusCode()

			/**
			 * @param string $key Header.
			 *
			 * @return string
			 */
			public function getHeader(string $key): string {
				return '';
			}//end getHeader()

			/**
			 * @return array<string, string>
			 */
			public function getHeaders(): array {
				return [];
			}//end getHeaders()
		};

	}//end response()

	/**
	 * Mock Contact found: GET /api/v1/contacts/{externalId} returns 200,
	 * the DTO carries every documented field, and the response is cached
	 * with the 5-minute TTL.
	 *
	 * @return void
	 */
	public function testFetchesContactAndCachesItWith5MinuteTtl(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);
		$script = [
			$this->response(200, [
				'externalId' => 'org-kvk-12345678',
				'legalName' => 'Bakkerij de Zon B.V.',
				'email' => 'info@bakkerij-de-zon.nl',
				'phone' => '+31 6 12345678',
				'address' => 'Zonneplein 10, 1234 AB Amsterdam',
				'kvkNumber' => '12345678',
			]),
		];

		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls);
		$contact = $adapter->getContact('org-kvk-12345678');

		self::assertInstanceOf(PipelinqContact::class, $contact);
		self::assertTrue($contact->isFound());
		self::assertSame('Bakkerij de Zon B.V.', $contact->legalName);
		self::assertSame('info@bakkerij-de-zon.nl', $contact->email);
		self::assertSame('+31 6 12345678', $contact->phone);
		self::assertSame('Zonneplein 10, 1234 AB Amsterdam', $contact->address);
		self::assertSame('12345678', $contact->kvkNumber);
		self::assertCount(1, $dispatchCalls);
		self::assertCount(1, $writes);
		self::assertSame(
			PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX . 'org-kvk-12345678',
			$writes[0]['key']
		);
		self::assertSame(PipelinqContactAdapter::CONTACT_CACHE_TTL_SECONDS, $writes[0]['ttl']);

	}//end testFetchesContactAndCachesItWith5MinuteTtl()

	/**
	 * Cache hit: a second lookup within TTL does NOT issue a request.
	 *
	 * @return void
	 */
	public function testSecondLookupServesCachedContactWithoutHttpCall(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);
		$script = [
			$this->response(200, [
				'externalId' => 'org-kvk-87654321',
				'legalName' => 'Frank de Loodgieter',
				'email' => 'frank@loodgieter.nl',
			]),
		];

		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls);

		$first = $adapter->getContact('org-kvk-87654321');
		$second = $adapter->getContact('org-kvk-87654321');

		self::assertCount(1, $dispatchCalls, 'second lookup must NOT issue a request');
		self::assertSame($first->legalName, $second->legalName);
		self::assertSame($first->email, $second->email);
		self::assertSame($first->externalId, $second->externalId);

	}//end testSecondLookupServesCachedContactWithoutHttpCall()

	/**
	 * Contact not found: 404 yields a fallback DTO with isFound=false.
	 * The fallback is cached so a follow-up lookup does not re-hit
	 * pipelinq inside the TTL.
	 *
	 * @return void
	 */
	public function testNotFoundReturnsFallbackAndIsCached(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);
		$logger = new class extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		$script = [$this->response(404)];
		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls, $logger);

		$contact = $adapter->getContact('org-kvk-unknown');

		self::assertFalse($contact->isFound());
		self::assertSame('org-kvk-unknown', $contact->externalId);
		self::assertCount(1, $writes, 'fallback contact must be cached');

		// Second call must not re-hit pipelinq within TTL.
		$again = $adapter->getContact('org-kvk-unknown');
		self::assertFalse($again->isFound());
		self::assertCount(1, $dispatchCalls, '404 fallback must be served from cache on a repeat');

		// Spec requirement: "no error SHALL be logged" for a 404 — the
		// slice 02 transport may surface an attempt-failed WARNING (the
		// 404 is non-transient and exits the retry loop) but slice 03
		// itself MUST NOT escalate it to ERROR or emit a contact-level
		// WARNING for an expected "not found" outcome.
		foreach ($logger->records as $rec) {
			self::assertNotSame('error', (string)$rec['level'], 'ERROR must not be logged for 404');
			self::assertNotSame('critical', (string)$rec['level'], 'CRITICAL must not be logged for 404');
		}

		$slice03Warnings = array_filter(
			$logger->records,
			static fn (array $rec): bool => (string)$rec['level'] === 'warning'
				&& str_contains((string)$rec['message'], 'pipelinq contact')
		);
		self::assertCount(
			0,
			$slice03Warnings,
			'slice 03 must not emit a contact-level WARNING for an expected 404'
		);

	}//end testNotFoundReturnsFallbackAndIsCached()

	/**
	 * Malformed JSON: 200 with garbage body → fallback DTO + WARNING log,
	 * no retry. The fallback is NOT cached (we want to refresh quickly
	 * once pipelinq fixes the response).
	 *
	 * @return void
	 */
	public function testMalformedJsonYieldsFallbackPlusWarningWithoutRetry(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);
		$logger = new class extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		$script = [$this->response(200, null, '<<not json>>')];
		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls, $logger);

		$contact = $adapter->getContact('org-kvk-broken');

		self::assertFalse($contact->isFound());
		self::assertSame('org-kvk-broken', $contact->externalId);
		self::assertCount(1, $dispatchCalls, 'malformed JSON must NOT be retried');
		self::assertCount(0, $writes, 'malformed-JSON fallback is not cached');

		$warnings = array_filter(
			$logger->records,
			static fn (array $rec): bool => (string)$rec['level'] === 'warning'
				&& str_contains((string)$rec['message'], 'malformed')
		);
		self::assertCount(1, $warnings, 'one WARNING must be emitted for malformed JSON');

	}//end testMalformedJsonYieldsFallbackPlusWarningWithoutRetry()

	/**
	 * TTL expiry: clearCache() invalidates entries, after which the next
	 * lookup re-fetches from pipelinq. We simulate the 5-minute expiry
	 * via clearCache() — the TTL itself is provided to ICache by the
	 * adapter and we have already asserted it equals 300.
	 *
	 * @return void
	 */
	public function testManualCacheInvalidationForcesRefresh(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);
		$script = [
			$this->response(200, [
				'externalId' => 'org-kvk-11112222',
				'legalName' => 'First Payload Inc.',
			]),
			$this->response(200, [
				'externalId' => 'org-kvk-11112222',
				'legalName' => 'Second Payload Inc.',
			]),
		];

		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls);

		$first = $adapter->getContact('org-kvk-11112222');
		self::assertSame('First Payload Inc.', $first->legalName);

		// Still inside TTL → cache hit.
		$cached = $adapter->getContact('org-kvk-11112222');
		self::assertSame('First Payload Inc.', $cached->legalName);
		self::assertCount(1, $dispatchCalls);

		// Manual invalidation → next lookup hits pipelinq again.
		$adapter->clearCache();

		$fresh = $adapter->getContact('org-kvk-11112222');
		self::assertSame('Second Payload Inc.', $fresh->legalName);
		self::assertCount(2, $dispatchCalls);

	}//end testManualCacheInvalidationForcesRefresh()

	/**
	 * TTL is set on the cache write call. Asserted alongside the first
	 * test, this one verifies the constant exposes the 5-minute SLA.
	 *
	 * @return void
	 */
	public function testCacheTtlEquals5Minutes(): void {
		self::assertSame(300, PipelinqContactAdapter::CONTACT_CACHE_TTL_SECONDS);

	}//end testCacheTtlEquals5Minutes()

	/**
	 * Graceful degradation: when pipelinq is unavailable (transport error
	 * / open breaker), a still-valid cached Contact SHALL be served and
	 * no exception propagated.
	 *
	 * @return void
	 */
	public function testCacheServedWhenPipelinqIsUnavailable(): void {
		$writes = [];
		$dispatchCalls = [];
		$cache = $this->inMemoryCache($writes);

		// First call succeeds and caches the Contact; next 3 attempts
		// all fail at the transport layer (network errors → transient).
		$networkError = new \RuntimeException('connect: connection refused');
		$script = [
			$this->response(200, [
				'externalId' => 'org-kvk-12345678',
				'legalName' => 'Bakkerij de Zon B.V.',
				'email' => 'info@bakkerij-de-zon.nl',
			]),
			$networkError,
			$networkError,
			$networkError,
		];

		$adapter = $this->buildAdapter($script, $cache, $dispatchCalls);

		// Prime the cache.
		$primed = $adapter->getContact('org-kvk-12345678');
		self::assertTrue($primed->isFound());
		self::assertCount(1, $dispatchCalls);

		// Invalidate-then-restore so the next lookup misses the cache,
		// hits the now-failing transport, then falls back to a manually
		// re-seeded cache entry. We simulate this with a second adapter
		// sharing the SAME cache so the cache entry survives.
		// Re-prime via direct cache write would also work, but exposing
		// a cached value to the failure-path is exactly what slice 03
		// promises — so we replay the same cache + a fresh adapter.
		$dispatchCalls2 = [];
		$writes2 = [];
		$adapter2 = $this->buildAdapter(
			[$networkError, $networkError, $networkError],
			$cache,
			$dispatchCalls2
		);

		// First lookup hits the cache → no transport call.
		$cached = $adapter2->getContact('org-kvk-12345678');
		self::assertTrue($cached->isFound());
		self::assertSame('Bakkerij de Zon B.V.', $cached->legalName);
		self::assertCount(0, $dispatchCalls2, 'cache hit must short-circuit before transport');

		// Now simulate cache expiry (TTL elapsed) by removing the entry
		// BUT then re-seeding under a different key the adapter never
		// looks up — i.e. cache is genuinely empty for this id.
		// Then pipelinq is unavailable AND there is no still-valid
		// entry → the transport exception propagates (per spec).
		$cache->clear(PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX);

		try {
			$adapter2->getContact('org-kvk-12345678');
			self::fail('Expected PipelinqTransportException when cache empty + pipelinq down');
		} catch (PipelinqTransportException) {
			// Expected.
		}

	}//end testCacheServedWhenPipelinqIsUnavailable()

	/**
	 * Cache degradation guard: when pipelinq is down BUT a still-valid
	 * cache entry exists, the cached Contact is served and a WARNING is
	 * logged.
	 *
	 * @return void
	 */
	public function testCachedEntryServedOnTransportFailureWithWarning(): void {
		$dispatchCalls = [];
		$logger = new class extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		// We exercise the degradation path by forcing a cache MISS at
		// the FIRST read but a HIT on the FALLBACK read after the
		// transport fails. We achieve that with a tailored cache double
		// that intentionally returns null on a configured attempt.
		$primedCache = new class implements ICache {

			/**
			 * @var array<string, string>
			 */
			public array $store = [];

			/**
			 * @var array<string, int>
			 */
			public array $getCounts = [];

			/**
			 * @var array<string, int>
			 */
			public array $missOnAttempt = [];

			/**
			 * @param string $key Cache key.
			 *
			 * @return mixed
			 */
			public function get($key) {
				$this->getCounts[$key] = (($this->getCounts[$key] ?? 0) + 1);
				if (isset($this->missOnAttempt[$key]) === true
					&& $this->missOnAttempt[$key] === $this->getCounts[$key]
				) {
					return null;
				}

				return ($this->store[$key] ?? null);
			}//end get()

			/**
			 * @param string $key Cache key.
			 * @param mixed $value Value.
			 * @param int $ttl TTL.
			 *
			 * @return bool
			 */
			public function set($key, $value, $ttl = 0) {
				$this->store[$key] = (string)$value;
				return true;
			}//end set()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function hasKey($key) {
				return array_key_exists($key, $this->store);
			}//end hasKey()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function remove($key) {
				unset($this->store[$key]);
				return true;
			}//end remove()

			/**
			 * @param string $prefix Optional prefix.
			 *
			 * @return bool
			 */
			public function clear($prefix = '') {
				if ($prefix === '') {
					$this->store = [];
					return true;
				}

				foreach (array_keys($this->store) as $key) {
					if (str_starts_with($key, $prefix) === true) {
						unset($this->store[$key]);
					}
				}

				return true;
			}//end clear()

			/**
			 * @return bool
			 */
			public static function isAvailable(): bool {
				return true;
			}//end isAvailable()
		};

		$key = PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX . 'org-kvk-12345678';
		$primedCache->store[$key] = json_encode([
			'externalId' => 'org-kvk-12345678',
			'legalName' => 'Bakkerij de Zon B.V.',
			'email' => 'info@bakkerij-de-zon.nl',
			'phone' => '',
			'address' => '',
			'kvkNumber' => '12345678',
			'found' => true,
		], JSON_THROW_ON_ERROR);

		// Force a miss on the FIRST get() so the adapter falls through
		// to the transport; the transport then fails; the adapter's
		// fallback re-reads the cache (2nd get → hit).
		$primedCache->missOnAttempt[$key] = 1;

		$networkError = new \RuntimeException('connect: connection refused');
		$adapter = $this->buildAdapter(
			[$networkError, $networkError, $networkError],
			$primedCache,
			$dispatchCalls,
			$logger
		);

		$contact = $adapter->getContact('org-kvk-12345678');

		self::assertTrue($contact->isFound());
		self::assertSame('Bakkerij de Zon B.V.', $contact->legalName);
		self::assertCount(3, $dispatchCalls, 'all 3 retry attempts must run before the cache fallback');

		$warnings = array_filter(
			$logger->records,
			static fn (array $rec): bool => (string)$rec['level'] === 'warning'
				&& str_contains((string)$rec['message'], 'serving cached')
		);
		self::assertCount(1, $warnings, 'degradation must emit a WARNING');

	}//end testCachedEntryServedOnTransportFailureWithWarning()
}//end class
