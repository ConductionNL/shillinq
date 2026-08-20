<?php

/**
 * Pipelinq customer-bridge metrics service.
 *
 * Slice 11 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * The chain emits structured logs at every interesting transition
 * (success/cache hit at DEBUG, transient failure + circuit-breaker
 * transitions at WARNING, permanent failures at ERROR). This service is
 * the OPS-VISIBILITY counterpart: it keeps lightweight in-memory + cached
 * counters so an admin dashboard (or the engine-owned Prometheus endpoint
 * `GET /api/metrics`, into which these series are merged via this service's
 * {@see IMetricsProvider} implementation) can answer:
 *
 *   - How many publishes succeeded vs failed over the last interval?
 *   - How many calls retried, and at what depth?
 *   - How many events are currently in the dead-letter queue?
 *   - What is the current circuit-breaker state?
 *
 * The counters live in {@see ICache} so they survive request boundaries
 * (the integration's primary consumer is the booking-created listener,
 * which runs in HTTP-request scope, not a single long-lived worker). The
 * service is deliberately framework-light: no DB tables, no migrations,
 * no schema deltas. An ops dashboard scrapes {@see snapshot()} or the
 * Prometheus controller; alerts (circuit-breaker open / dead-letter
 * growth) are documented in `docs/Integrations/pipelinq-architecture.md`.
 *
 * Concurrency note: ICache backends (APCu, Redis, Memcached) do not
 * guarantee atomic increment for our `inc()`/`set()` pair across
 * processes. The counters are best-effort observability — a missed
 * increment under heavy contention is acceptable, the alert thresholds
 * are coarse-grained. We pay attention to NOT logging anything from
 * this service so it stays pure and unit-testable.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Aggregates counters and gauges for the pipelinq customer-bridge integration.
 *
 * AppHost adoption (adopt-apphost): also the app's {@see IMetricsProvider} —
 * registered under the alias `OCA\OpenRegister\AppHost\IMetricsProvider::shillinq`
 * (ADR-035 discovery), so the `{"kind":"provider"}` descriptor in the manifest
 * `observability.metrics` block merges these series into the engine-owned
 * Prometheus exposition served by GenericMetricsController at `GET /api/metrics`.
 * The series carry over sample-for-sample from the retired bespoke
 * `renderPrometheus()` exposition (the engine adds the `shillinq_` prefix, so
 * sample names here omit it).
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
 * @spec openspec/specs/apphost-adoption/spec.md
 */
final class CustomerBridgeMetricsService implements IMetricsProvider {
	/**
	 * Cache namespace shared by every counter so a flush wipes the lot.
	 *
	 * @var string
	 */
	public const CACHE_NAMESPACE = 'shillinq.pipelinq.metrics';

	/**
	 * Successful Contact reads (cache hits + API 200s).
	 *
	 * @var string
	 */
	public const COUNTER_CONTACT_SUCCESS = 'contact.success';

	/**
	 * Contact reads that ended in a fallback (404 / malformed JSON).
	 *
	 * @var string
	 */
	public const COUNTER_CONTACT_FALLBACK = 'contact.fallback';

	/**
	 * Cache hits served without touching pipelinq.
	 *
	 * @var string
	 */
	public const COUNTER_CONTACT_CACHE_HIT = 'contact.cache.hit';

	/**
	 * Stale-cache hits served because pipelinq was unavailable.
	 *
	 * @var string
	 */
	public const COUNTER_CONTACT_CACHE_STALE = 'contact.cache.stale';

	/**
	 * Successful timeline publishes (synchronous).
	 *
	 * @var string
	 */
	public const COUNTER_TIMELINE_PUBLISH_SUCCESS = 'timeline.publish.success';

	/**
	 * Failed timeline publishes that were handed to the retry queue.
	 *
	 * @var string
	 */
	public const COUNTER_TIMELINE_PUBLISH_DEFERRED = 'timeline.publish.deferred';

	/**
	 * Permanent failures (401, dead-letter exhaustion).
	 *
	 * @var string
	 */
	public const COUNTER_PERMANENT_FAILURE = 'permanent.failure';

	/**
	 * Total retry attempts issued (sum across all calls).
	 *
	 * @var string
	 */
	public const COUNTER_RETRY_ATTEMPTS = 'retry.attempts';

	/**
	 * Maximum observed retry depth — the deepest a single call went.
	 *
	 * @var string
	 */
	public const GAUGE_RETRY_DEPTH_MAX = 'retry.depth.max';

	/**
	 * Current dead-letter queue size (events that exhausted the retry budget).
	 *
	 * @var string
	 */
	public const GAUGE_DEAD_LETTER_COUNT = 'dead_letter.count';

	/**
	 * Current circuit-breaker state (closed/open/half_open).
	 *
	 * @var string
	 */
	public const GAUGE_CIRCUIT_STATE = 'circuit.state';

	/**
	 * Underlying cache layer.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache layer factory; we open one cache scoped to {@see self::CACHE_NAMESPACE}.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createLocal(self::CACHE_NAMESPACE);

	}//end __construct()

	/**
	 * Record a successful Contact read.
	 *
	 * @param bool $fromCache TRUE when served from the local cache, FALSE when fetched from pipelinq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordContactSuccess(bool $fromCache): void {
		$this->increment(key: self::COUNTER_CONTACT_SUCCESS);
		if ($fromCache === true) {
			$this->increment(key: self::COUNTER_CONTACT_CACHE_HIT);
		}

	}//end recordContactSuccess()

	/**
	 * Record a Contact read that returned a fallback DTO (404, malformed JSON).
	 *
	 * @param string $reason Short reason tag (`not_found`, `malformed`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordContactFallback(string $reason): void {
		$this->increment(key: self::COUNTER_CONTACT_FALLBACK);
		// Per-reason tagging — kept as a sibling counter so dashboards
		// can break the fallback rate down without joining tables.
		$this->increment(key: self::COUNTER_CONTACT_FALLBACK . '.' . $this->normaliseTag(tag: $reason));

	}//end recordContactFallback()

	/**
	 * Record a stale-cache read served because pipelinq was unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordContactStaleServed(): void {
		$this->increment(key: self::COUNTER_CONTACT_CACHE_STALE);

	}//end recordContactStaleServed()

	/**
	 * Record a successful synchronous timeline publish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordTimelinePublishSuccess(): void {
		$this->increment(key: self::COUNTER_TIMELINE_PUBLISH_SUCCESS);

	}//end recordTimelinePublishSuccess()

	/**
	 * Record a failed publish that was handed to the retry queue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordTimelinePublishDeferred(): void {
		$this->increment(key: self::COUNTER_TIMELINE_PUBLISH_DEFERRED);

	}//end recordTimelinePublishDeferred()

	/**
	 * Record a permanent failure (401 auth, dead-letter exhaustion).
	 *
	 * @param string $reason Short tag (`auth`, `dead_letter`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordPermanentFailure(string $reason): void {
		$this->increment(key: self::COUNTER_PERMANENT_FAILURE);
		$this->increment(key: self::COUNTER_PERMANENT_FAILURE . '.' . $this->normaliseTag(tag: $reason));

	}//end recordPermanentFailure()

	/**
	 * Record one retry attempt and update the max-depth gauge.
	 *
	 * @param int $attempt 1-based attempt counter that was just issued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordRetryAttempt(int $attempt): void {
		if ($attempt < 1) {
			return;
		}

		$this->increment(key: self::COUNTER_RETRY_ATTEMPTS);

		$current = $this->readInt(key: self::GAUGE_RETRY_DEPTH_MAX);
		if ($attempt > $current) {
			$this->cache->set(self::GAUGE_RETRY_DEPTH_MAX, (string)$attempt);
		}

	}//end recordRetryAttempt()

	/**
	 * Update the dead-letter queue size gauge (called by slice 09's queue).
	 *
	 * @param int $count Current dead-letter queue size.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordDeadLetterCount(int $count): void {
		$this->cache->set(self::GAUGE_DEAD_LETTER_COUNT, (string)max(0, $count));

	}//end recordDeadLetterCount()

	/**
	 * Update the circuit-breaker state gauge.
	 *
	 * @param string $state One of CircuitBreaker::STATE_CLOSED/OPEN/HALF_OPEN.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function recordCircuitState(string $state): void {
		$this->cache->set(self::GAUGE_CIRCUIT_STATE, $state);

	}//end recordCircuitState()

	/**
	 * Snapshot every counter + gauge for a dashboard or the Prometheus endpoint.
	 *
	 * Counters that have never been touched read as 0; the circuit state
	 * gauge defaults to `closed`. Stable key ordering keeps diffing
	 * dashboard payloads cheap.
	 *
	 * @return array<string, int|string> Counter/gauge map.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function snapshot(): array {
		return [
			self::COUNTER_CONTACT_SUCCESS => $this->readInt(key: self::COUNTER_CONTACT_SUCCESS),
			self::COUNTER_CONTACT_FALLBACK => $this->readInt(key: self::COUNTER_CONTACT_FALLBACK),
			self::COUNTER_CONTACT_FALLBACK . '.not_found' => $this->readInt(key: self::COUNTER_CONTACT_FALLBACK . '.not_found'),
			self::COUNTER_CONTACT_FALLBACK . '.malformed' => $this->readInt(key: self::COUNTER_CONTACT_FALLBACK . '.malformed'),
			self::COUNTER_CONTACT_CACHE_HIT => $this->readInt(key: self::COUNTER_CONTACT_CACHE_HIT),
			self::COUNTER_CONTACT_CACHE_STALE => $this->readInt(key: self::COUNTER_CONTACT_CACHE_STALE),
			self::COUNTER_TIMELINE_PUBLISH_SUCCESS => $this->readInt(key: self::COUNTER_TIMELINE_PUBLISH_SUCCESS),
			self::COUNTER_TIMELINE_PUBLISH_DEFERRED => $this->readInt(key: self::COUNTER_TIMELINE_PUBLISH_DEFERRED),
			self::COUNTER_PERMANENT_FAILURE => $this->readInt(key: self::COUNTER_PERMANENT_FAILURE),
			self::COUNTER_PERMANENT_FAILURE . '.auth' => $this->readInt(key: self::COUNTER_PERMANENT_FAILURE . '.auth'),
			self::COUNTER_PERMANENT_FAILURE . '.dead_letter' => $this->readInt(key: self::COUNTER_PERMANENT_FAILURE . '.dead_letter'),
			self::COUNTER_RETRY_ATTEMPTS => $this->readInt(key: self::COUNTER_RETRY_ATTEMPTS),
			self::GAUGE_RETRY_DEPTH_MAX => $this->readInt(key: self::GAUGE_RETRY_DEPTH_MAX),
			self::GAUGE_DEAD_LETTER_COUNT => $this->readInt(key: self::GAUGE_DEAD_LETTER_COUNT),
			self::GAUGE_CIRCUIT_STATE => $this->readString(
				key: self::GAUGE_CIRCUIT_STATE,
				default: CircuitBreaker::STATE_CLOSED
			),
		];

	}//end snapshot()

	/**
	 * Reset every counter — exposed for tests and the admin "Reset metrics" action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function reset(): void {
		$this->cache->clear();

	}//end reset()

	/**
	 * Produce the AppHost {@see MetricSample} set for the engine renderer.
	 *
	 * Carries over every series the retired bespoke {@see renderPrometheus()}
	 * exposition emitted, sample-for-sample. The engine's PrometheusRenderer
	 * prefixes each sample name with the app id (`shillinq_`), so the names
	 * here drop that prefix: `pipelinq_contact_success_total` renders as
	 * `shillinq_pipelinq_contact_success_total`, exactly the pre-adoption name.
	 * The circuit-breaker state is a labelled gauge with value 1 so a dashboard
	 * can `label_values()` over it (unchanged contract).
	 *
	 * @return MetricSample[] The provider's samples.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function metrics(): array {
		$snapshot = $this->snapshot();

		// [snapshot-key => [metric-name-without-app-prefix, prometheus-type]].
		$map = [
			self::COUNTER_CONTACT_SUCCESS => ['pipelinq_contact_success_total', 'counter'],
			self::COUNTER_CONTACT_FALLBACK => ['pipelinq_contact_fallback_total', 'counter'],
			self::COUNTER_CONTACT_CACHE_HIT => ['pipelinq_contact_cache_hit_total', 'counter'],
			self::COUNTER_CONTACT_CACHE_STALE => ['pipelinq_contact_cache_stale_total', 'counter'],
			self::COUNTER_TIMELINE_PUBLISH_SUCCESS => ['pipelinq_timeline_publish_success_total', 'counter'],
			self::COUNTER_TIMELINE_PUBLISH_DEFERRED => ['pipelinq_timeline_publish_deferred_total', 'counter'],
			self::COUNTER_PERMANENT_FAILURE => ['pipelinq_permanent_failure_total', 'counter'],
			self::COUNTER_RETRY_ATTEMPTS => ['pipelinq_retry_attempts_total', 'counter'],
			self::GAUGE_RETRY_DEPTH_MAX => ['pipelinq_retry_depth_max', 'gauge'],
			self::GAUGE_DEAD_LETTER_COUNT => ['pipelinq_dead_letter_count', 'gauge'],
		];

		$samples = [];
		foreach ($map as $key => [$metric, $type]) {
			$samples[] = MetricSample::single(
				name: $metric,
				type: $type,
				help: 'Pipelinq customer-bridge ' . str_replace('_', ' ', $metric),
				value: (int)($snapshot[$key] ?? 0)
			);
		}

		$state = (string)($snapshot[self::GAUGE_CIRCUIT_STATE] ?? CircuitBreaker::STATE_CLOSED);
		$samples[] = new MetricSample(
			name: 'pipelinq_circuit_state',
			type: 'gauge',
			help: 'Pipelinq customer-bridge circuit-breaker state',
			samples: [['labels' => ['state' => $state], 'value' => 1]]
		);

		return $samples;
	}//end metrics()

	/**
	 * Increment a counter by 1 (best-effort).
	 *
	 * @param string $key Counter key.
	 *
	 * @return void
	 */
	private function increment(string $key): void {
		$current = $this->readInt(key: $key);
		$this->cache->set($key, (string)($current + 1));

	}//end increment()

	/**
	 * Read an integer counter; missing values default to 0.
	 *
	 * @param string $key Counter key.
	 *
	 * @return int
	 */
	private function readInt(string $key): int {
		$raw = $this->cache->get($key);
		if (is_string($raw) === false && is_int($raw) === false) {
			return 0;
		}

		if (is_int($raw) === true) {
			return $raw;
		}

		if ($raw === '' || ctype_digit($raw) === false) {
			return 0;
		}

		return (int)$raw;
	}//end readInt()

	/**
	 * Read a string gauge; missing values fall back to the supplied default.
	 *
	 * @param string $key Gauge key.
	 * @param string $default Default returned on a cache miss.
	 *
	 * @return string
	 */
	private function readString(string $key, string $default): string {
		$raw = $this->cache->get($key);
		if (is_string($raw) === true && $raw !== '') {
			return $raw;
		}

		return $default;
	}//end readString()

	/**
	 * Normalise a free-form reason tag for cache-key safety.
	 *
	 * Lower-cases, replaces every non-alphanumeric run with `_`, and
	 * truncates to 32 chars so a runaway upstream string cannot blow up
	 * the cache namespace.
	 *
	 * @param string $tag Caller-supplied tag.
	 *
	 * @return string
	 */
	private function normaliseTag(string $tag): string {
		$lower = strtolower($tag);
		$safe = preg_replace('/[^a-z0-9]+/', '_', $lower);
		if (is_string($safe) === false || $safe === '') {
			return 'unknown';
		}

		$clamped = substr(trim($safe, '_'), 0, 32);
		if ($clamped === '') {
			return 'unknown';
		}

		return $clamped;
	}//end normaliseTag()
}//end class
