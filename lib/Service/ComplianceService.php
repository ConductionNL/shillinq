<?php

/**
 * BBV Compliance Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 08 of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032). Thin imperative orchestration + caching surface around the
 * declarative aggregation defined on the `BBVProgramme` schema by slice
 * 02 (`x-openregister-aggregations`). The maths lives in the
 * aggregation; this service NEVER reimplements the compliance formulas
 * (REQ-BBVW-005 / giant D3 / ADR-031).
 *
 * Responsibilities:
 *
 *   1. `computeComplianceStatus($programme)` — read the materialised
 *      aggregation values (`totalBudget`, `ytdSpend`, `utilization`,
 *      `complianceStatus`) off a programme object and return the shape
 *      `{utilization, status, budget, ytdSpend}` the dashboard widgets
 *      bind to.
 *   2. Cache the per-programme result for 1 hour (REQ-BBVW-006).
 *   3. Invalidate cache entries when a `GLTransaction` / `GLLine` /
 *      `GLTransactionLine` object is created or updated (registered as
 *      an `ObjectCreatedEvent` / `ObjectUpdatedEvent` listener in
 *      `Application.php`).
 *
 * The declarative aggregation runs server-side at query time so the
 * cached envelope is purely a re-fetch dampener — invalidating on GL
 * write guarantees the next dashboard render sees a fresh aggregation.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin compliance-status service: reads the declarative aggregation
 * and caches the per-programme envelope until a GL transaction write
 * invalidates it.
 *
 * The class hovers at the PHPMD class-complexity ceiling because each
 * responsibility (cache lookup, cache write, programme lookup, envelope
 * shaping, cents coercion, cache-key composition, OR entity normalisation)
 * is split into a small focused helper rather than a long monolith. The
 * trade-off is intentional — fewer branches per method beats fewer
 * methods per class for this service's correctness review surface.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)           Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class ComplianceService {
	/**
	 * Cache TTL in seconds (REQ-BBVW-006: 1 hour).
	 */
	public const CACHE_TTL_SECONDS = 3600;

	/**
	 * Cache namespace — the shillinq BBV compliance scope.
	 */
	private const CACHE_NAMESPACE = 'shillinq-bbv-compliance';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ICacheFactory $cacheFactory Distributed cache factory (compliance cache).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the compliance status envelope for a single programme.
	 *
	 * The maths (TotalBudget, YTDSpend, Utilization, ComplianceStatus)
	 * is declarative on the BBVProgramme schema (slice 02). This method
	 * only reads those materialised values back and shapes them into
	 * the `{utilization, status, budget, ytdSpend}` envelope.
	 *
	 * Accepts either:
	 *
	 *   - a `programmeCode` string (e.g. `"2.3.2"`) — resolved against
	 *     the configured shillinq register;
	 *   - an associative array shaped like a BBVProgramme record with
	 *     `programmeCode` + the materialised aggregation fields already
	 *     populated by the engine (preferred call shape for the widget
	 *     controller, which already paged the list).
	 *
	 * Slice-09 addition (REQ-BBVW-006): the optional `fiscalYear` argument
	 * scopes the aggregation read to a single fiscal year. The fiscal
	 * year is folded into the cache key so a programme that exists across
	 * multiple fiscal years (e.g. "2.3.2" in both FY 2025 and FY 2026)
	 * does not return stale cross-FY envelopes. When the caller does not
	 * supply a fiscalYear (legacy slice-08 call shape) the cache key
	 * elides the FY segment and the lookup uses every fiscal year — that
	 * branch is kept compatible for the existing tests + the cache
	 * invalidation listener.
	 *
	 * Returns a compliance envelope. Empty/unconfigured programmes return
	 * `utilization=0.0`, `status="unconfigured"`, `budget=0` and `ytdSpend=0`.
	 *
	 * @param array<string,mixed>|string $programme Programme record or programmeCode.
	 * @param int|null $fiscalYear Optional fiscal-year scope (REQ-BBVW-006).
	 *
	 * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function computeComplianceStatus(array|string $programme, ?int $fiscalYear = null): array {
		$programmeCode = $this->resolveProgrammeCode(programme: $programme);
		if ($programmeCode === '') {
			return $this->emptyEnvelope(programmeCode: '');
		}

		// Only fold the fiscal year into the cache key when the caller
		// *explicitly* supplied one. Auto-pulling the FY from the record
		// would silently change the cache key shape for existing slice-08
		// callers that pass programme arrays without expecting per-FY
		// partitioning — the dashboard envelope builder is the only
		// caller that knows the active FY and passes it explicitly.
		$cacheKey = $this->buildCacheKey(programmeCode: $programmeCode, fiscalYear: $fiscalYear);
		$cache = $this->getCache();
		$cached = $this->cachedEnvelope(cache: $cache, cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$envelope = $this->freshEnvelope(programme: $programme, fiscalYear: $fiscalYear);
		$this->writeCached(cache: $cache, cacheKey: $cacheKey, envelope: $envelope);

		return $envelope;
	}//end computeComplianceStatus()

	/**
	 * A cached envelope, or null when there is no cache or no usable entry.
	 *
	 * @param ICache|null $cache    Cache handle.
	 * @param string      $cacheKey Cache key.
	 *
	 * @return array<string,mixed>|null
	 */
	private function cachedEnvelope(ICache|null $cache, string $cacheKey): array|null {
		if ($cache === null) {
			return null;
		}

		$cached = $cache->get($cacheKey);
		if (is_array($cached) === true && isset($cached['status']) === true) {
			return $cached;
		}

		return null;
	}//end cachedEnvelope()

	/**
	 * The envelope computed from the programme record: the record given, or
	 * the one loaded by programmeCode (within the fiscal year when given).
	 *
	 * @param array<string,mixed>|string $programme  Programme record or programmeCode.
	 * @param integer|null               $fiscalYear Optional fiscal-year scope.
	 *
	 * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
	 */
	private function freshEnvelope(array|string $programme, int|null $fiscalYear): array {
		$programmeCode = $this->resolveProgrammeCode(programme: $programme);
		$record = $programme;
		if (is_array($programme) === false) {
			$record = $this->loadProgrammeRecord(programmeCode: $programmeCode, fiscalYear: $fiscalYear);
		}

		if ($record === null) {
			return $this->emptyEnvelope(programmeCode: $programmeCode);
		}

		return $this->envelopeFromRecord(record: $record, programmeCode: $programmeCode);
	}//end freshEnvelope()

	/**
	 * Write a cached envelope, fail-soft on cache write error.
	 *
	 * @param ICache|null $cache Cache handle (no-op when null).
	 * @param string $cacheKey Cache key.
	 * @param array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string} $envelope Envelope to cache.
	 *
	 * @return void
	 */
	private function writeCached(?ICache $cache, string $cacheKey, array $envelope): void {
		if ($cache === null) {
			return;
		}

		try {
			$cache->set($cacheKey, $envelope, self::CACHE_TTL_SECONDS);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq compliance service: cache set failed',
				['exception' => $e->getMessage()]
			);
		}

	}//end writeCached()

	/**
	 * Invalidate a single programme's cached envelope.
	 *
	 * Slice-09 (REQ-BBVW-006): when the fiscalYear is null we drop every
	 * fiscal-year variant of the programme's cache entry — easier than
	 * tracking which years are warmed and a cheap on the cold path.
	 *
	 * @param string $programmeCode Programme code (e.g. "2.3.2").
	 * @param int|null $fiscalYear Optional FY scope to invalidate; null = all FYs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function invalidate(string $programmeCode, ?int $fiscalYear = null): void {
		$cache = $this->getCache();
		if ($cache === null || $programmeCode === '') {
			return;
		}

		// With a fiscal year this drops that year's entry; without one it drops
		// the legacy (no-FY) entry, and ::clear() / invalidateAll() handle the
		// broader sweep when callers want it. Removing one programme across
		// every FY would require iterating cache keys we don't track here.
		try {
			$cache->remove($this->buildCacheKey(programmeCode: $programmeCode, fiscalYear: $fiscalYear));
		} catch (Throwable $e) {
			$context = ['programmeCode' => $programmeCode, 'fiscalYear' => $fiscalYear, 'exception' => $e->getMessage()];
			$this->logger->warning('Shillinq compliance service: cache remove failed', $context);
		}

	}//end invalidate()

	/**
	 * Clear every cached compliance envelope.
	 *
	 * Called by the GL-transaction listener: a single posted line can
	 * touch any number of mapped programmes (allocations split across
	 * programmes per REQ-BBVW-002), so the cheapest correct response is
	 * to drop every entry under the shillinq compliance namespace and
	 * let the next dashboard render repopulate from the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function invalidateAll(): void {
		$cache = $this->getCache();
		if ($cache === null) {
			return;
		}

		try {
			$cache->clear();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq compliance service: cache clear failed',
				['exception' => $e->getMessage()]
			);
		}

	}//end invalidateAll()

	/**
	 * Resolve the programme code from either an array record or string.
	 *
	 * @param array<string,mixed>|string $programme Programme handle.
	 *
	 * @return string Trimmed programmeCode or '' if not resolvable.
	 */
	private function resolveProgrammeCode(array|string $programme): string {
		if (is_string($programme) === true) {
			return trim($programme);
		}

		$code = $programme['programmeCode'] ?? '';
		if (is_string($code) === false) {
			return '';
		}

		return trim($code);
	}//end resolveProgrammeCode()

	/**
	 * Load a BBVProgramme record from OpenRegister by programmeCode.
	 *
	 * Slice-09: when a fiscalYear is provided the filter is narrowed so
	 * the lookup never returns a prior-year programme (REQ-BBVW-006). The
	 * declarative aggregation also keys on fiscalYear, so this is just a
	 * tighter pre-filter; the math result is unaffected.
	 *
	 * @param string $programmeCode Programme code (e.g. "2.3.2").
	 * @param int|null $fiscalYear Optional fiscal-year scope.
	 *
	 * @return array<string,mixed>|null Record or null when unavailable.
	 */
	private function loadProgrammeRecord(string $programmeCode, ?int $fiscalYear = null): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->firstProgramme(programmeCode: $programmeCode, fiscalYear: $fiscalYear);
		} catch (Throwable $e) {
			$context = ['programmeCode' => $programmeCode, 'fiscalYear' => $fiscalYear, 'exception' => $e->getMessage()];
			$this->logger->error('Shillinq compliance service: programme lookup failed', $context);
		}

		return null;
	}//end loadProgrammeRecord()

	/**
	 * The first BBVProgramme matching the code (and fiscal year), as an array.
	 *
	 * @param string       $programmeCode Programme code (e.g. "2.3.2").
	 * @param integer|null $fiscalYear    Optional fiscal-year scope.
	 *
	 * @return array<string,mixed>|null Record, or null when none matches.
	 */
	private function firstProgramme(string $programmeCode, int|null $fiscalYear): array|null {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$filters = ['programmeCode' => $programmeCode];
		if ($fiscalYear !== null) {
			$filters['fiscalYear'] = $fiscalYear;
		}

		$query = ['filters' => $filters, 'limit' => 1];
		$records = $objectService->setRegister($this->settings->getRegisterSlug())->setSchema('BBVProgramme')->findAll($query);
		foreach ($records as $candidate) {
			return $this->toArray(object: $candidate);
		}

		return null;
	}//end firstProgramme()

	/**
	 * Shape an envelope from a programme record's materialised values.
	 *
	 * The aggregation engine writes `totalBudget`, `ytdSpend`,
	 * `utilization`, `utilizationPercentage`, and `complianceStatus`
	 * onto each returned BBVProgramme object (slice 02). This method
	 * reads them, applies sensible fallbacks, and returns the envelope.
	 *
	 * No formula is recomputed here — the only logic is the same
	 * "unconfigured when no mappings" fallback the declarative
	 * aggregation already encodes, kept defensive in case the engine
	 * returns nulls for a programme without mappings.
	 *
	 * @param array<string,mixed> $record Programme record.
	 * @param string $programmeCode Resolved programme code.
	 *
	 * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
	 */
	private function envelopeFromRecord(array $record, string $programmeCode): array {
		return [
			'programmeCode' => $programmeCode,
			'utilization' => $this->utilizationOf(record: $record),
			'status' => $this->statusOf(record: $record),
			'budget' => $this->coerceCents(value: $record['totalBudget'] ?? null),
			'ytdSpend' => $this->coerceCents(value: $record['ytdSpend'] ?? null),
		];

	}//end envelopeFromRecord()

	/**
	 * The materialised utilization, else ytdSpend ÷ budget, else 0.
	 *
	 * @param array<string,mixed> $record Programme record.
	 *
	 * @return float
	 */
	private function utilizationOf(array $record): float {
		$utilization = $record['utilization'] ?? null;
		if (is_numeric($utilization) === true) {
			return (float)$utilization;
		}

		$budget = $this->coerceCents(value: $record['totalBudget'] ?? null);
		if ($budget > 0) {
			return ((float)$this->coerceCents(value: $record['ytdSpend'] ?? null) / (float)$budget);
		}

		return 0.0;
	}//end utilizationOf()

	/**
	 * The engine's compliance bucket, or `unconfigured` when absent.
	 *
	 * Engine bucketing absent: only `unconfigured` is a safe imperative
	 * fallback — the threshold semantics are declarative on the schema and
	 * MUST NOT be re-encoded here (ADR-031 / giant D3).
	 *
	 * @param array<string,mixed> $record Programme record.
	 *
	 * @return string
	 */
	private function statusOf(array $record): string {
		$status = $record['complianceStatus'] ?? null;
		if (is_string($status) === false || $status === '') {
			return 'unconfigured';
		}

		return $status;
	}//end statusOf()

	/**
	 * Empty envelope for unresolvable or unconfigured programmes.
	 *
	 * @param string $programmeCode Programme code (may be '').
	 *
	 * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
	 */
	private function emptyEnvelope(string $programmeCode): array {
		return [
			'programmeCode' => $programmeCode,
			'utilization' => 0.0,
			'status' => 'unconfigured',
			'budget' => 0,
			'ytdSpend' => 0,
		];

	}//end emptyEnvelope()

	/**
	 * Normalise a value to integer cents (ADR-031 money rule).
	 *
	 * @param mixed $value Materialised cents value (engine returns int; defensive coerce for string-ints).
	 *
	 * @return int Integer cents (0 when value is unparseable).
	 */
	private function coerceCents(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		if ($this->isDigitString(value: $value) === true) {
			return (int)$value;
		}

		if ($this->isFiniteFloat(value: $value) === true) {
			// Float-from-engine is unexpected (the aggregation declares
			// `unit: cents` integer), but if a downstream serialiser
			// turned it into a float we round half-up to the nearest
			// cent rather than silently truncate.
			return (int)round($value);
		}

		return 0;
	}//end coerceCents()

	/**
	 * Whether a value is a string of digits, optionally led by minus signs.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 */
	private function isDigitString(mixed $value): bool {
		return is_string($value) === true && ctype_digit(ltrim($value, '-')) === true;
	}//end isDigitString()

	/**
	 * Whether a value is a finite float.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 *
	 * @psalm-assert-if-true float $value
	 */
	private function isFiniteFloat(mixed $value): bool {
		return is_float($value) === true && is_finite($value) === true;
	}//end isFiniteFloat()

	/**
	 * Compose the cache key for a programme.
	 *
	 * Slice-09: when a fiscalYear is supplied the key gains an `:fy:<n>`
	 * segment so cross-FY entries cannot collide (REQ-BBVW-006). Callers
	 * that omit the fiscalYear get the legacy un-suffixed key — preserved
	 * for backward compatibility with slice-08 callers that have no FY
	 * to scope by yet.
	 *
	 * @param string $programmeCode Programme code.
	 * @param int|null $fiscalYear Optional fiscal-year scope.
	 *
	 * @return string Stable cache key.
	 */
	private function buildCacheKey(string $programmeCode, ?int $fiscalYear = null): string {
		$key = (self::CACHE_NAMESPACE . ':' . $programmeCode);
		if ($fiscalYear !== null) {
			$key .= (':fy:' . $fiscalYear);
		}

		return $key;
	}//end buildCacheKey()

	/**
	 * Resolve the compliance cache (distributed when available).
	 *
	 * @return ICache|null Cache handle or null when no factory is available.
	 */
	private function getCache(): ?ICache {
		try {
			if ($this->cacheFactory->isLocalCacheAvailable() === true) {
				return $this->cacheFactory->createLocal(self::CACHE_NAMESPACE);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq compliance service: cache factory threw',
				['exception' => $e->getMessage()]
			);
		}

		return null;
	}//end getCache()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object Either an array or an OR entity exposing getObject().
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		$payload = ($this->entityPayload(object: $object) ?? $this->serialisedPayload(object: $object));
		if (is_array($payload) === true) {
			return $payload;
		}

		return [];
	}//end toArray()

	/**
	 * An entity's getObject() payload when it is an array, otherwise null.
	 *
	 * @param mixed $object An OR entity, or anything else.
	 *
	 * @return array<string,mixed>|null
	 */
	private function entityPayload(mixed $object): array|null {
		if (is_object($object) === false || method_exists($object, 'getObject') === false) {
			return null;
		}

		$payload = $object->getObject();
		if (is_array($payload) === true) {
			return $payload;
		}

		return null;
	}//end entityPayload()

	/**
	 * An entity's jsonSerialize() result, or null when it has none.
	 *
	 * @param mixed $object An OR entity, or anything else.
	 *
	 * @return mixed
	 */
	private function serialisedPayload(mixed $object): mixed {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return $object->jsonSerialize();
		}

		return null;
	}//end serialisedPayload()
}//end class
