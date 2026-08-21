<?php

/**
 * Unit tests for RateCardResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RateCardResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema-aware fake ObjectService — returns per-schema rows so we can drive
 * the RateRecord → RateCard fallback chain.
 *
 * findAll() applies the query's equality filters (AND across every key),
 * exactly like the real OpenRegister ObjectService::findAll(). That matters:
 * RateCardResolver's tenant guard IS the compound
 * `rateCardId`+`administrationId` filter it issues, so a double that ignored
 * filters would make every cross-tenant assertion vacuous — the guard would
 * "pass" with the administrationId filter deleted.
 *
 * @phpstan-type SchemaRows array<string, array<int, array<string,mixed>>>
 */
final class FakeRateObjectService {

	/**
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $rows;

	private string $schema = '';

	/**
	 * @param array<string, array<int, array<string,mixed>>> $rows Seed rows.
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;

	}//end __construct()

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $config Find configuration — the `filters` map
	 *                                    is applied as an AND of equality
	 *                                    comparisons, like the real
	 *                                    ObjectService.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		$rows = ($this->rows[$this->schema] ?? []);
		$filters = ($config['filters'] ?? []);
		if (is_array($filters) === false || $filters === []) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()
}//end class

/**
 * Verifies REQ-INV rate-snapshot resolution: latest effective rate wins,
 * future/expired records skipped, RateCard fallback, hard-coded fallback,
 * and toCents() float/int handling — plus the REQ-001 administration scope
 * (ADR-005 Rule 3) that keeps another tenant's negotiated rate unreachable.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
final class RateCardResolverTest extends TestCase {

	/**
	 * The caller's own administration.
	 */
	private const ADMIN_A = 'adm-a';

	/**
	 * Another tenant's administration — never reachable from ADMIN_A.
	 */
	private const ADMIN_B = 'adm-b';

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the subject with the given schema-rows map.
	 *
	 * @param array<string, array<int, array<string,mixed>>> $rows Rows.
	 *
	 * @return RateCardResolver
	 */
	private function svc(array $rows): RateCardResolver {
		$fake = new FakeRateObjectService($rows);
		$this->container->method('get')->willReturn($fake);

		return new RateCardResolver($this->container, $this->appConfig, $this->logger);
	}//end svc()

	/**
	 * Active RateRecord (float) is converted to cents and returned.
	 *
	 * @return void
	 */
	public function testActiveRateRecordIsReturnedInCents(): void {
		$rows = [
			'RateRecord' => [
				[
					'rateCardId' => 'rc-1',
					'administrationId' => self::ADMIN_A,
					'resourceType' => 'senior_consultant',
					'effectiveDate' => '2026-01-01',
					'hourlyRate' => 175.00,
					'rateCardVersion' => 'v2',
				],
			],
		];

		$result = $this->svc($rows)->resolveRate('rc-1', 'senior_consultant', '2026-03-15', self::ADMIN_A);

		self::assertSame(17500, $result['rateCents']);
		self::assertSame('v2', $result['rateCardVersion']);
		self::assertSame('2026-01-01', $result['effectiveDate']);
		self::assertSame('EUR', $result['currency']);

	}//end testActiveRateRecordIsReturnedInCents()

	/**
	 * Future effectiveDate is filtered out; expired expiresAt is filtered out.
	 *
	 * @return void
	 */
	public function testFutureAndExpiredRecordsAreFiltered(): void {
		$rows = [
			'RateRecord' => [
				['rateCardId' => 'rc', 'administrationId' => self::ADMIN_A, 'resourceType' => 't', 'effectiveDate' => '2027-01-01', 'hourlyRate' => 250.00],
				['rateCardId' => 'rc', 'administrationId' => self::ADMIN_A, 'resourceType' => 't', 'effectiveDate' => '2025-01-01', 'expiresAt' => '2025-12-31', 'hourlyRate' => 100.00],
				['rateCardId' => 'rc', 'administrationId' => self::ADMIN_A, 'resourceType' => 't', 'effectiveDate' => '2026-01-01', 'hourlyRate' => 150.00],
			],
		];

		$result = $this->svc($rows)->resolveRate('rc', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(15000, $result['rateCents']);
		self::assertSame('2026-01-01', $result['effectiveDate']);

	}//end testFutureAndExpiredRecordsAreFiltered()

	/**
	 * When no RateRecord matches, fall back to the RateCard's
	 * `defaultHourlyRate`.
	 *
	 * @return void
	 */
	public function testFallbackToRateCardDefault(): void {
		$rows = [
			'RateRecord' => [],
			'RateCard' => [
				['scheduleId' => 'rc-1', 'administrationId' => self::ADMIN_A, 'defaultHourlyRate' => 120.00, 'currency' => 'USD', 'version' => 'v3'],
			],
		];

		$result = $this->svc($rows)->resolveRate('rc-1', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(12000, $result['rateCents']);
		self::assertSame('USD', $result['currency']);
		self::assertSame('v3', $result['rateCardVersion']);

	}//end testFallbackToRateCardDefault()

	/**
	 * Hard fallback when no RateRecord and no RateCard matches:
	 * 10000 cents / EUR / 'fallback' + warning log.
	 *
	 * @return void
	 */
	public function testHardFallbackWhenNothingMatches(): void {
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('falling back to'));

		$result = $this->svc([])->resolveRate('rc-missing', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(10000, $result['rateCents']);
		self::assertSame('EUR', $result['currency']);
		self::assertSame('fallback', $result['rateCardVersion']);

	}//end testHardFallbackWhenNothingMatches()

	/**
	 * Integer hourlyRate is treated as already-cents (toCents() passthrough).
	 *
	 * @return void
	 */
	public function testIntegerHourlyRateIsTreatedAsCents(): void {
		$rows = [
			'RateRecord' => [
				['rateCardId' => 'rc', 'administrationId' => self::ADMIN_A, 'resourceType' => 't', 'effectiveDate' => '2026-01-01', 'rateCents' => 17500],
			],
		];

		$result = $this->svc($rows)->resolveRate('rc', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(17500, $result['rateCents']);

	}//end testIntegerHourlyRateIsTreatedAsCents()

	/**
	 * ObjectService throwing → resolver swallows + logs error + falls back to
	 * hard default.
	 *
	 * @return void
	 */
	public function testObjectServiceFailureFallsThroughToHardDefault(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR down'));

		$this->logger->expects(self::atLeastOnce())->method('error');
		$this->logger->expects(self::atLeastOnce())->method('warning');

		$svc = new RateCardResolver($this->container, $this->appConfig, $this->logger);
		$result = $svc->resolveRate('rc-1', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(10000, $result['rateCents']);
		self::assertSame('fallback', $result['rateCardVersion']);

	}//end testObjectServiceFailureFallsThroughToHardDefault()

	/**
	 * RateCard fallback lookup also tries the `id` key when `scheduleId`
	 * yields nothing.
	 *
	 * @return void
	 */
	public function testRateCardFallbackTriesIdWhenScheduleIdMisses(): void {
		// First findAll returns RateRecord = [], second returns RateCard = []
		// (scheduleId filter), third returns the RateCard (id filter).
		$callCount = 0;
		$fake = new class($callCount) {
			public int $calls = 0;
			public function __construct(
				public int $startCount,
			) {
				$this->calls = $startCount;
			}
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}
			public string $schema = '';
			/** @return array<int,array<string,mixed>> */
			public function findAll(array $config = []): array {
				$this->calls++;
				if ($this->schema === 'RateRecord') {
					return [];
				}

				if ($this->schema === 'RateCard') {
					// first call (scheduleId filter) → empty
					// second call (id filter) → return the card
					if (isset($config['filters']['id']) === true || isset($config['id']) === true) {
						return [['id' => 'rc-1', 'defaultHourlyRate' => 88.00, 'version' => 'v1']];
					}

					return [];
				}

				return [];
			}
		};

		$this->container->method('get')->willReturn($fake);
		$svc = new RateCardResolver($this->container, $this->appConfig, $this->logger);

		$result = $svc->resolveRate('rc-1', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(8800, $result['rateCents']);
		self::assertSame('v1', $result['rateCardVersion']);

	}//end testRateCardFallbackTriesIdWhenScheduleIdMisses()

	/**
	 * REQ-001 (ADR-005 Rule 3) — cross-tenant RateRecord is unreachable.
	 *
	 * Administration A resolves a rateCardId that exists only under
	 * administration B. Before the fix the lookup filtered on
	 * (rateCardId, resourceType) alone, so B's negotiated €999/hr record was
	 * returned and snapshotted onto A's invoice line. It must now resolve to
	 * the generic fallback — indistinguishable from an unknown id — while B
	 * asking for its OWN card still gets the real rate (the positive control
	 * that proves the guard did not simply break the feature).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testCrossTenantRateRecordIsNotResolved(): void {
		$rows = [
			'RateRecord' => [
				[
					'rateCardId' => 'rc-victim',
					'administrationId' => self::ADMIN_B,
					'resourceType' => 'senior_consultant',
					'effectiveDate' => '2026-01-01',
					'hourlyRate' => 999.00,
					'rateCardVersion' => 'victim-v9',
				],
			],
		];

		$svc = $this->svc($rows);

		// Attacker (administration A) references administration B's rate card.
		$leaked = $svc->resolveRate('rc-victim', 'senior_consultant', '2026-03-15', self::ADMIN_A);

		self::assertSame(10000, $leaked['rateCents'], "administration B's rate leaked into administration A");
		self::assertSame('fallback', $leaked['rateCardVersion']);
		self::assertNotSame('victim-v9', $leaked['rateCardVersion']);

		// Positive control: the owner still resolves its own rate.
		$owned = $svc->resolveRate('rc-victim', 'senior_consultant', '2026-03-15', self::ADMIN_B);

		self::assertSame(99900, $owned['rateCents']);
		self::assertSame('victim-v9', $owned['rateCardVersion']);

	}//end testCrossTenantRateRecordIsNotResolved()

	/**
	 * REQ-001 — the RateCard-default fallback path is scoped too.
	 *
	 * resolveRate() has a second lookup (RateCard by scheduleId, then by id)
	 * that a guard on the RateRecord query alone would leave wide open: a
	 * simple rate card with no per-resource records would still hand over
	 * another tenant's defaultHourlyRate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testCrossTenantRateCardDefaultIsNotResolved(): void {
		$rows = [
			'RateRecord' => [],
			'RateCard' => [
				[
					'id' => 'rc-victim',
					'scheduleId' => 'rc-victim',
					'administrationId' => self::ADMIN_B,
					'defaultHourlyRate' => 777.00,
					'currency' => 'CHF',
					'version' => 'victim-card',
				],
			],
		];

		$svc = $this->svc($rows);

		$leaked = $svc->resolveRate('rc-victim', 't', '2026-03-15', self::ADMIN_A);

		self::assertSame(10000, $leaked['rateCents'], "administration B's default rate leaked into administration A");
		self::assertSame('EUR', $leaked['currency']);
		self::assertSame('fallback', $leaked['rateCardVersion']);

		// Positive control: the owner still resolves its own default rate.
		$owned = $svc->resolveRate('rc-victim', 't', '2026-03-15', self::ADMIN_B);

		self::assertSame(77700, $owned['rateCents']);
		self::assertSame('CHF', $owned['currency']);
		self::assertSame('victim-card', $owned['rateCardVersion']);

	}//end testCrossTenantRateCardDefaultIsNotResolved()

	/**
	 * REQ-001 — an empty administration scope fails closed.
	 *
	 * No register is read at all: an unscoped call can never be proven
	 * tenant-safe, so it yields the generic fallback rather than whatever the
	 * unfiltered query would have matched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testEmptyAdministrationScopeFailsClosed(): void {
		$rows = [
			'RateRecord' => [
				[
					'rateCardId' => 'rc-victim',
					'administrationId' => self::ADMIN_B,
					'resourceType' => 't',
					'effectiveDate' => '2026-01-01',
					'hourlyRate' => 999.00,
					'rateCardVersion' => 'victim-v9',
				],
			],
		];

		$result = $this->svc($rows)->resolveRate('rc-victim', 't', '2026-03-15', '');

		self::assertSame(10000, $result['rateCents']);
		self::assertSame('fallback', $result['rateCardVersion']);

	}//end testEmptyAdministrationScopeFailsClosed()

}//end class
