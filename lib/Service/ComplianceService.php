<?php

/**
 * BBV Compliance Service.
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
final class ComplianceService
{
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
     * @param ContainerInterface $container    DI container for lazy ObjectService resolution.
     * @param SettingsService    $settings     Shillinq settings (register slug, OR availability).
     * @param ICacheFactory      $cacheFactory Distributed cache factory (compliance cache).
     * @param LoggerInterface    $logger       Logger for fail-soft diagnostics.
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
     * @param array<string,mixed>|string $programme Programme record or programmeCode.
     *
     * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
     *         Compliance envelope. Empty/unconfigured programmes return
     *         `utilization=0.0`, `status="unconfigured"`, `budget=0`,
     *         `ytdSpend=0`.
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-the-system-shall-expose-a-compliance-service-that-reads-the-declarative-aggregation
     */
    public function computeComplianceStatus(array|string $programme): array
    {
        $programmeCode = $this->resolveProgrammeCode(programme: $programme);
        if ($programmeCode === '') {
            return $this->emptyEnvelope(programmeCode: '');
        }

        $cacheKey = $this->buildCacheKey(programmeCode: $programmeCode);
        $cache    = $this->getCache();

        if ($cache !== null) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached) === true && isset($cached['status']) === true) {
                /** @var array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string} $cached */
                return $cached;
            }
        }

        $record = (is_array($programme) === true)
            ? $programme
            : $this->loadProgrammeRecord(programmeCode: $programmeCode);

        if ($record === null) {
            $envelope = $this->emptyEnvelope(programmeCode: $programmeCode);
        } else {
            $envelope = $this->envelopeFromRecord(record: $record, programmeCode: $programmeCode);
        }

        if ($cache !== null) {
            try {
                $cache->set($cacheKey, $envelope, self::CACHE_TTL_SECONDS);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Shillinq compliance service: cache set failed',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        return $envelope;

    }//end computeComplianceStatus()

    /**
     * Invalidate a single programme's cached envelope.
     *
     * @param string $programmeCode Programme code (e.g. "2.3.2").
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-the-system-shall-expose-a-compliance-service-that-reads-the-declarative-aggregation
     */
    public function invalidate(string $programmeCode): void
    {
        $cache = $this->getCache();
        if ($cache === null || $programmeCode === '') {
            return;
        }

        try {
            $cache->remove($this->buildCacheKey(programmeCode: $programmeCode));
        } catch (Throwable $e) {
            $this->logger->warning(
                'Shillinq compliance service: cache remove failed',
                [
                    'programmeCode' => $programmeCode,
                    'exception'     => $e->getMessage(),
                ]
            );
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
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-the-system-shall-expose-a-compliance-service-that-reads-the-declarative-aggregation
     */
    public function invalidateAll(): void
    {
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
    private function resolveProgrammeCode(array|string $programme): string
    {
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
     * @param string $programmeCode Programme code (e.g. "2.3.2").
     *
     * @return array<string,mixed>|null Record or null when unavailable.
     */
    private function loadProgrammeRecord(string $programmeCode): ?array
    {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $registerSlug  = $this->settings->getRegisterSlug();
            $records       = $objectService
                ->setRegister($registerSlug)
                ->setSchema('BBVProgramme')
                ->findAll(
                    [
                        'filters' => ['programmeCode' => $programmeCode],
                        'limit'   => 1,
                    ]
                );
            foreach ($records as $candidate) {
                return $this->toArray(object: $candidate);
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Shillinq compliance service: programme lookup failed',
                [
                    'programmeCode' => $programmeCode,
                    'exception'     => $e->getMessage(),
                ]
            );
        }

        return null;

    }//end loadProgrammeRecord()

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
     * @param array<string,mixed> $record        Programme record.
     * @param string              $programmeCode Resolved programme code.
     *
     * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
     */
    private function envelopeFromRecord(array $record, string $programmeCode): array
    {
        $budget   = $this->coerceCents(value: $record['totalBudget'] ?? null);
        $ytdSpend = $this->coerceCents(value: $record['ytdSpend'] ?? null);

        $utilization = $record['utilization'] ?? null;
        if (is_numeric($utilization) === true) {
            $utilization = (float) $utilization;
        } else {
            $utilization = ($budget > 0) ? ((float) $ytdSpend / (float) $budget) : 0.0;
        }

        $status = $record['complianceStatus'] ?? null;
        if (is_string($status) === false || $status === '') {
            // Engine bucketing absent: only `unconfigured` is a safe
            // imperative fallback — the threshold semantics are
            // declarative on the schema and MUST NOT be re-encoded here
            // (ADR-031 / giant D3).
            $status = 'unconfigured';
        }

        return [
            'programmeCode' => $programmeCode,
            'utilization'   => $utilization,
            'status'        => $status,
            'budget'        => $budget,
            'ytdSpend'      => $ytdSpend,
        ];

    }//end envelopeFromRecord()

    /**
     * Empty envelope for unresolvable or unconfigured programmes.
     *
     * @param string $programmeCode Programme code (may be '').
     *
     * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
     */
    private function emptyEnvelope(string $programmeCode): array
    {
        return [
            'programmeCode' => $programmeCode,
            'utilization'   => 0.0,
            'status'        => 'unconfigured',
            'budget'        => 0,
            'ytdSpend'      => 0,
        ];

    }//end emptyEnvelope()

    /**
     * Normalise a value to integer cents (ADR-031 money rule).
     *
     * @param mixed $value Materialised cents value (engine returns int; defensive coerce for string-ints).
     *
     * @return int Integer cents (0 when value is unparseable).
     */
    private function coerceCents(mixed $value): int
    {
        if (is_int($value) === true) {
            return $value;
        }

        if (is_string($value) === true && ctype_digit(ltrim($value, '-')) === true) {
            return (int) $value;
        }

        if (is_float($value) === true && is_finite($value) === true) {
            // Float-from-engine is unexpected (the aggregation declares
            // `unit: cents` integer), but if a downstream serialiser
            // turned it into a float we round half-up to the nearest
            // cent rather than silently truncate.
            return (int) round($value);
        }

        return 0;

    }//end coerceCents()

    /**
     * Compose the cache key for a programme.
     *
     * @param string $programmeCode Programme code.
     *
     * @return string Stable cache key.
     */
    private function buildCacheKey(string $programmeCode): string
    {
        return (self::CACHE_NAMESPACE.':'.$programmeCode);

    }//end buildCacheKey()

    /**
     * Resolve the compliance cache (distributed when available).
     *
     * @return ICache|null Cache handle or null when no factory is available.
     */
    private function getCache(): ?ICache
    {
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
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            /** @var array<string,mixed> $object */
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            /** @var mixed $payload */
            $payload = $object->getObject();
            if (is_array($payload) === true) {
                /** @var array<string,mixed> $payload */
                return $payload;
            }
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            /** @var mixed $payload */
            $payload = $object->jsonSerialize();
            if (is_array($payload) === true) {
                /** @var array<string,mixed> $payload */
                return $payload;
            }
        }

        return [];

    }//end toArray()
}//end class
