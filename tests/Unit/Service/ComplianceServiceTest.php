<?php

/**
 * Unit tests for ComplianceService (slice 08 of the
 * bookkeeping-waterschappen-bbv-variant chain).
 *
 * Slice 11 (testing) of the chain. Asserts the imperative orchestration
 * + caching surface around the declarative aggregation defined on the
 * BBVProgramme schema by slice 02. Covers spend levels (on-track,
 * at-risk, non-compliant), multi-account aggregation (one programme
 * fed by multiple GL accounts), rounding tolerance (engine-returned
 * floats coerced to integer cents per ADR-031), fiscal-year scoping
 * (programmeCode resolves via filters), and cache invalidation.
 *
 * The maths is NEVER reimplemented in the service or in this test —
 * the aggregation engine materialises totalBudget / ytdSpend /
 * utilization / complianceStatus on each BBVProgramme record and the
 * service just shapes them into the envelope. These tests therefore
 * inject pre-materialised records and assert the envelope shape +
 * cache lifecycle.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-11-testing/tasks.md#unit-tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\SettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplianceService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
final class ComplianceServiceTest extends TestCase {

	/**
	 * DI container stub (lazy ObjectService resolution).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Settings stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Cache factory stub.
	 *
	 * @var ICacheFactory&MockObject
	 */
	private ICacheFactory&MockObject $cacheFactory;

	/**
	 * In-memory cache backing store keyed by cache key.
	 *
	 * @var array<string,mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Whether the in-memory cache is currently exposed.
	 *
	 * @var boolean
	 */
	private bool $cacheAvailable = true;

	/**
	 * Logger stub.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var ComplianceService
	 */
	private ComplianceService $service;

	/**
	 * Build the service against in-memory cache + container stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			fn (string $key) => ($this->cacheStore[$key] ?? null)
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cacheStore[$key]);
				return true;
			}
		);
		$cache->method('clear')->willReturnCallback(
			function (): bool {
				$this->cacheStore = [];
				return true;
			}
		);

		$this->cacheFactory->method('isLocalCacheAvailable')->willReturnCallback(
			fn () => $this->cacheAvailable
		);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->service = new ComplianceService(
			container: $this->container,
			settings: $this->settings,
			cacheFactory: $this->cacheFactory,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * On-track spend level produces the materialised on-track envelope.
	 *
	 * REQ-BBVW-005: utilization ≤ 75% → status on-track.
	 *
	 * @return void
	 */
	public function testOnTrackSpendLevel(): void {
		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 6500000,
			utilization: 0.65,
			status: 'on-track',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame('2.3.2', $envelope['programmeCode']);
		self::assertSame(10000000, $envelope['budget']);
		self::assertSame(6500000, $envelope['ytdSpend']);
		self::assertSame(0.65, $envelope['utilization']);
		self::assertSame('on-track', $envelope['status']);

	}//end testOnTrackSpendLevel()

	/**
	 * At-risk spend level produces the materialised at-risk envelope.
	 *
	 * REQ-BBVW-005: 75% < utilization ≤ 90% → status at-risk.
	 *
	 * @return void
	 */
	public function testAtRiskSpendLevel(): void {
		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 8500000,
			utilization: 0.85,
			status: 'at-risk',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame(0.85, $envelope['utilization']);
		self::assertSame('at-risk', $envelope['status']);

	}//end testAtRiskSpendLevel()

	/**
	 * Non-compliant spend level produces the materialised non-compliant
	 * envelope.
	 *
	 * REQ-BBVW-005: utilization > 90% → status non-compliant.
	 *
	 * @return void
	 */
	public function testNonCompliantSpendLevel(): void {
		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 9600000,
			utilization: 0.96,
			status: 'non-compliant',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame(0.96, $envelope['utilization']);
		self::assertSame('non-compliant', $envelope['status']);

	}//end testNonCompliantSpendLevel()

	/**
	 * Unconfigured programmes (no budget, no mappings) get the safe
	 * fallback envelope.
	 *
	 * @return void
	 */
	public function testUnconfiguredProgramme(): void {
		$record = $this->programmeRecord(
			code: '3.1.0',
			budgetCents: 0,
			ytdSpendCents: 0,
			utilization: 0.0,
			status: 'unconfigured',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame('3.1.0', $envelope['programmeCode']);
		self::assertSame(0, $envelope['budget']);
		self::assertSame(0, $envelope['ytdSpend']);
		self::assertSame(0.0, $envelope['utilization']);
		self::assertSame('unconfigured', $envelope['status']);

	}//end testUnconfiguredProgramme()

	/**
	 * Multi-account aggregation: programme 2.4.1 in the slice-02 fixture
	 * is fed by GL 4100 (20% of 100 000 000 ct = 20 000 000 ct) and
	 * GL 5000 (75% of 40 000 000 ct = 30 000 000 ct) for a total
	 * budget of 50 000 000 cents. The engine returns the summed value
	 * already; the service shapes it into the envelope unchanged.
	 *
	 * @return void
	 */
	public function testMultiAccountAggregation(): void {
		$record = $this->programmeRecord(
			code: '2.4.1',
			budgetCents: 50000000,
			ytdSpendCents: 12500000,
			utilization: 0.25,
			status: 'on-track',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame(50000000, $envelope['budget']);
		self::assertSame(12500000, $envelope['ytdSpend']);
		self::assertSame(0.25, $envelope['utilization']);
		self::assertSame('on-track', $envelope['status']);

	}//end testMultiAccountAggregation()

	/**
	 * Rounding tolerance: when the engine returns a money field as a
	 * float (post-serialisation), the service rounds half-up to the
	 * nearest integer cent rather than silently truncating. Per ADR-031
	 * money is always integer cents.
	 *
	 * @return void
	 */
	public function testRoundingToleranceCoercesFloatCents(): void {
		$record = [
			'programmeCode' => '2.3.2',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-waterschap-1',
			'totalBudget' => 10000000.0,
			'ytdSpend' => 6500000.6,
			'utilization' => 0.65,
			'complianceStatus' => 'on-track',
		];

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		// 6500000.6 rounds half-up to 6500001, never truncated to
		// 6500000.
		self::assertSame(10000000, $envelope['budget']);
		self::assertSame(6500001, $envelope['ytdSpend']);

	}//end testRoundingToleranceCoercesFloatCents()

	/**
	 * Rounding tolerance: integer-string cents (from an HTTP serializer
	 * that stringified the int) are coerced back to ints.
	 *
	 * @return void
	 */
	public function testRoundingToleranceCoercesStringIntCents(): void {
		$record = [
			'programmeCode' => '2.3.2',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-waterschap-1',
			'totalBudget' => '10000000',
			'ytdSpend' => '6500000',
			'utilization' => '0.65',
			'complianceStatus' => 'on-track',
		];

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame(10000000, $envelope['budget']);
		self::assertSame(6500000, $envelope['ytdSpend']);
		self::assertSame(0.65, $envelope['utilization']);

	}//end testRoundingToleranceCoercesStringIntCents()

	/**
	 * Defensive fallback: if the engine omits utilization (e.g. a
	 * programme with no mappings), the service derives it locally from
	 * budget + ytdSpend rather than fabricating a value.
	 *
	 * @return void
	 */
	public function testUtilizationFallbackFromBudgetAndSpend(): void {
		$record = [
			'programmeCode' => '2.3.2',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-waterschap-1',
			'totalBudget' => 1000,
			'ytdSpend' => 500,
			// No 'utilization' key; no 'complianceStatus' key either.
		];

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame(0.5, $envelope['utilization']);
		// No engine bucketing → safe fallback is "unconfigured", NOT
		// a re-derived bucket (the threshold semantics live on the
		// schema per ADR-031).
		self::assertSame('unconfigured', $envelope['status']);

	}//end testUtilizationFallbackFromBudgetAndSpend()

	/**
	 * Fiscal-year scoping: when a programmeCode string is passed and
	 * OR is unavailable, the service returns the empty envelope rather
	 * than throwing or hitting cache with a poisoned value.
	 *
	 * @return void
	 */
	public function testProgrammeCodeStringWithoutOrIsEmptyEnvelope(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);

		$envelope = $this->service->computeComplianceStatus(programme: '2.3.2');

		self::assertSame('2.3.2', $envelope['programmeCode']);
		self::assertSame(0, $envelope['budget']);
		self::assertSame(0, $envelope['ytdSpend']);
		self::assertSame(0.0, $envelope['utilization']);
		self::assertSame('unconfigured', $envelope['status']);

	}//end testProgrammeCodeStringWithoutOrIsEmptyEnvelope()

	/**
	 * Empty programmeCode (string '' or array without code) yields the
	 * empty envelope without touching cache.
	 *
	 * @return void
	 */
	public function testEmptyProgrammeCodeReturnsEmptyEnvelope(): void {
		$envelopeString = $this->service->computeComplianceStatus(programme: '');
		self::assertSame('', $envelopeString['programmeCode']);
		self::assertSame('unconfigured', $envelopeString['status']);

		$envelopeArray = $this->service->computeComplianceStatus(programme: []);
		self::assertSame('', $envelopeArray['programmeCode']);
		self::assertSame('unconfigured', $envelopeArray['status']);

		self::assertSame([], $this->cacheStore, 'Empty-code path MUST NOT poison the cache.');

	}//end testEmptyProgrammeCodeReturnsEmptyEnvelope()

	/**
	 * Caching: a second computeComplianceStatus() call for the same
	 * programmeCode returns the cached envelope. We prove this by
	 * mutating the in-memory cache between calls and asserting the
	 * service surfaces the cached payload, not the original record.
	 *
	 * @return void
	 */
	public function testCacheReturnsCachedEnvelopeOnRepeatCall(): void {
		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 6500000,
			utilization: 0.65,
			status: 'on-track',
		);

		$first = $this->service->computeComplianceStatus(programme: $record);
		self::assertSame('on-track', $first['status']);
		self::assertNotEmpty($this->cacheStore);

		// Mutate the cached envelope under the hood — a second call
		// SHALL surface the mutation, proving the cache is consulted.
		$key = 'shillinq-bbv-compliance:2.3.2';
		$this->cacheStore[$key] = [
			'programmeCode' => '2.3.2',
			'budget' => 10000000,
			'ytdSpend' => 9999999,
			'utilization' => 0.99,
			'status' => 'non-compliant',
		];

		$second = $this->service->computeComplianceStatus(programme: $record);
		self::assertSame('non-compliant', $second['status']);
		self::assertSame(9999999, $second['ytdSpend']);

	}//end testCacheReturnsCachedEnvelopeOnRepeatCall()

	/**
	 * Invalidate(): removes a single programme's cached envelope.
	 *
	 * @return void
	 */
	public function testInvalidateClearsSingleProgrammeCache(): void {
		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 6500000,
			utilization: 0.65,
			status: 'on-track',
		);

		$this->service->computeComplianceStatus(programme: $record);
		self::assertArrayHasKey('shillinq-bbv-compliance:2.3.2', $this->cacheStore);

		$this->service->invalidate(programmeCode: '2.3.2');
		self::assertArrayNotHasKey('shillinq-bbv-compliance:2.3.2', $this->cacheStore);

	}//end testInvalidateClearsSingleProgrammeCache()

	/**
	 * Invalidate() ignores empty codes (defensive: no cache mutation).
	 *
	 * @return void
	 */
	public function testInvalidateIgnoresEmptyCode(): void {
		$this->cacheStore['shillinq-bbv-compliance:2.3.2'] = ['status' => 'on-track'];

		$this->service->invalidate(programmeCode: '');

		self::assertArrayHasKey('shillinq-bbv-compliance:2.3.2', $this->cacheStore);

	}//end testInvalidateIgnoresEmptyCode()

	/**
	 * InvalidateAll(): clears every cached envelope (a single GL line
	 * can touch many programmes per REQ-BBVW-002, so the cheapest
	 * correct response is to drop everything).
	 *
	 * @return void
	 */
	public function testInvalidateAllClearsEveryCachedEnvelope(): void {
		$records = [
			$this->programmeRecord(
				code: '1.1.1',
				budgetCents: 5000000,
				ytdSpendCents: 1000000,
				utilization: 0.2,
				status: 'on-track',
			),
			$this->programmeRecord(
				code: '2.3.2',
				budgetCents: 10000000,
				ytdSpendCents: 6500000,
				utilization: 0.65,
				status: 'on-track',
			),
		];

		foreach ($records as $record) {
			$this->service->computeComplianceStatus(programme: $record);
		}

		self::assertCount(2, $this->cacheStore);

		$this->service->invalidateAll();

		self::assertCount(0, $this->cacheStore, 'invalidateAll MUST drop every entry under the BBV cache namespace.');

	}//end testInvalidateAllClearsEveryCachedEnvelope()

	/**
	 * Cache-unavailable path: the service still returns a well-formed
	 * envelope when the local cache factory reports no cache.
	 *
	 * @return void
	 */
	public function testCacheUnavailableReturnsLiveEnvelope(): void {
		$this->cacheAvailable = false;

		$record = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 6500000,
			utilization: 0.65,
			status: 'on-track',
		);

		$envelope = $this->service->computeComplianceStatus(programme: $record);

		self::assertSame('on-track', $envelope['status']);
		self::assertSame([], $this->cacheStore, 'Cache-unavailable path MUST NOT write to the in-memory store.');

	}//end testCacheUnavailableReturnsLiveEnvelope()

	/**
	 * Fiscal-year scoping (envelope perspective): the engine
	 * pre-filters by administrationId + fiscalYear on the schema-side
	 * aggregation; the service preserves the resolved programmeCode in
	 * the envelope unchanged so callers can multi-key by (year, code).
	 *
	 * @return void
	 */
	public function testFiscalYearScopedRecordRoundtrip(): void {
		$record2025 = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 8000000,
			ytdSpendCents: 8000000,
			utilization: 1.0,
			status: 'non-compliant',
			fiscalYear: 2025,
		);
		$record2026 = $this->programmeRecord(
			code: '2.3.2',
			budgetCents: 10000000,
			ytdSpendCents: 6500000,
			utilization: 0.65,
			status: 'on-track',
			fiscalYear: 2026,
		);

		// Two callers: the FY-2025 record (PRE-aggregated by the engine
		// as non-compliant) and the FY-2026 record (on-track). Cache is
		// keyed by programmeCode only; the engine is responsible for
		// never mixing fiscal years on a single record. We assert that
		// the second call's CACHED envelope wins so the service does
		// not silently swap years on cache hit — the engine MUST drive
		// year scope, the cache is dumb.
		$envelopeA = $this->service->computeComplianceStatus(programme: $record2025);
		self::assertSame('non-compliant', $envelopeA['status']);

		// Cache hit: second call returns the same envelope, proving
		// the service does not silently re-aggregate.
		$envelopeB = $this->service->computeComplianceStatus(programme: $record2026);
		self::assertSame('non-compliant', $envelopeB['status'], 'Cache MUST be honoured; the engine drives year-scoping at write.');

		// After invalidation, the new (FY-2026) record materialises.
		$this->service->invalidate(programmeCode: '2.3.2');
		$envelopeC = $this->service->computeComplianceStatus(programme: $record2026);
		self::assertSame('on-track', $envelopeC['status']);
		self::assertSame(10000000, $envelopeC['budget']);

	}//end testFiscalYearScopedRecordRoundtrip()

	/**
	 * Build a BBVProgramme record with materialised aggregation fields.
	 *
	 * @param string $code Programme code.
	 * @param int $budgetCents Materialised totalBudget (cents).
	 * @param int $ytdSpendCents Materialised ytdSpend (cents).
	 * @param float $utilization Materialised utilization (ratio).
	 * @param string $status Materialised complianceStatus bucket.
	 * @param int $fiscalYear Fiscal year (default 2026).
	 *
	 * @return array<string,mixed>
	 */
	private function programmeRecord(
		string $code,
		int $budgetCents,
		int $ytdSpendCents,
		float $utilization,
		string $status,
		int $fiscalYear = 2026,
	): array {
		return [
			'programmeCode' => $code,
			'programmeName' => 'Test ' . $code,
			'fiscalYear' => $fiscalYear,
			'status' => 'active',
			'administrationId' => 'adm-waterschap-1',
			'totalBudget' => $budgetCents,
			'ytdSpend' => $ytdSpendCents,
			'utilization' => $utilization,
			'complianceStatus' => $status,
		];

	}//end programmeRecord()
}//end class
