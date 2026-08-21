<?php

/**
 * Unit tests for RetainerResolver.
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

use OCA\Shillinq\Service\RetainerResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies Task 25 (#111) retainer-schedule resolution: picks the latest
 * effective version on or before the invoice month, honours endDate, falls
 * back to a safe zero schedule with warning, and applies toCents() to mixed
 * money inputs — plus the REQ-001 administration scope (ADR-005 Rule 3) that
 * keeps another tenant's retainer unreachable.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
final class RetainerResolverTest extends TestCase {

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
	 * Build the subject + wire a fluent fake ObjectService that applies the
	 * query's equality filters (AND across every key), exactly like the real
	 * OpenRegister ObjectService::findAll().
	 *
	 * The filter-honouring behaviour is load-bearing, not incidental:
	 * RetainerResolver's tenant guard IS the compound
	 * `scheduleId`+`administrationId` filter it issues, so a double that
	 * ignored filters would make every cross-tenant assertion vacuous — the
	 * guard would "pass" with the administrationId filter deleted.
	 *
	 * @param array<int, array<string,mixed>> $rows Rows to return.
	 *
	 * @return RetainerResolver
	 */
	private function svcWithRows(array $rows): RetainerResolver {
		$fake = new class($rows) {
			/** @param array<int, array<string,mixed>> $rows */
			public function __construct(
				private array $rows,
			) {
			}
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			/**
			 * @param array<string,mixed> $config Find configuration — the
			 *                                    `filters` map is applied as an
			 *                                    AND of equality comparisons.
			 * @return array<int, array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				if (is_array($filters) === false || $filters === []) {
					return $this->rows;
				}

				return array_values(
					array_filter(
						$this->rows,
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
			}
		};

		$this->container->method('get')->willReturn($fake);

		return new RetainerResolver($this->container, $this->appConfig, $this->logger);
	}//end svcWithRows()

	/**
	 * No matching schedule → warning + zeroed shape.
	 *
	 * @return void
	 */
	public function testNoScheduleYieldsZeroAmountAndWarning(): void {
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('no active schedule'));

		$svc = $this->svcWithRows([]);
		$result = $svc->resolveRetainerAmount('sched-1', '2026-03-15', self::ADMIN_A);

		self::assertSame(0, $result['monthlyAmountCents']);
		self::assertNull($result['overageHoursThreshold']);
		self::assertNull($result['overageHourlyRateCents']);
		self::assertSame('Retainer', $result['label']);

	}//end testNoScheduleYieldsZeroAmountAndWarning()

	/**
	 * Single active schedule is picked and money is converted to cents.
	 *
	 * @return void
	 */
	public function testActiveScheduleIsPickedWithMoneyInCents(): void {
		$rows = [
			[
				'scheduleId' => 'sched-1',
				'administrationId' => self::ADMIN_A,
				'effectiveDate' => '2026-01-01',
				'monthlyAmount' => 1234.56,
				'overageHoursThreshold' => 40,
				'overageHourlyRate' => 125.00,
				'label' => 'Premium Retainer',
			],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('sched-1', '2026-03-15', self::ADMIN_A);

		self::assertSame(123456, $result['monthlyAmountCents']);
		self::assertSame(40.0, $result['overageHoursThreshold']);
		self::assertSame(12500, $result['overageHourlyRateCents']);
		self::assertSame('2026-01-01', $result['effectiveDate']);
		self::assertSame('Premium Retainer', $result['label']);

	}//end testActiveScheduleIsPickedWithMoneyInCents()

	/**
	 * Latest-effective wins among versions that all bracket the invoice month.
	 *
	 * @return void
	 */
	public function testLatestEffectiveVersionWins(): void {
		// Floats are treated as euro decimals (×100); the resolver picks the
		// latest effective version that brackets the invoice month.
		$rows = [
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 10.00],
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2026-02-01', 'monthlyAmount' => 15.00],
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2025-12-01', 'monthlyAmount' => 9.00],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15', self::ADMIN_A);

		self::assertSame(1500, $result['monthlyAmountCents']);
		self::assertSame('2026-02-01', $result['effectiveDate']);

	}//end testLatestEffectiveVersionWins()

	/**
	 * A future effective date is filtered out.
	 *
	 * @return void
	 */
	public function testFutureEffectiveDateIsSkipped(): void {
		$rows = [
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2026-04-01', 'monthlyAmount' => 9999],
		];

		$this->logger->expects(self::once())->method('warning');

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15', self::ADMIN_A);

		self::assertSame(0, $result['monthlyAmountCents']);

	}//end testFutureEffectiveDateIsSkipped()

	/**
	 * A schedule whose endDate is before the invoice month is filtered out.
	 *
	 * @return void
	 */
	public function testExpiredEndDateIsSkipped(): void {
		$rows = [
			[
				'scheduleId' => 's',
				'administrationId' => self::ADMIN_A,
				'effectiveDate' => '2026-01-01',
				'endDate' => '2026-02-28',
				'monthlyAmount' => 9999,
			],
		];

		$this->logger->expects(self::once())->method('warning');

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15', self::ADMIN_A);

		self::assertSame(0, $result['monthlyAmountCents']);

	}//end testExpiredEndDateIsSkipped()

	/**
	 * Integer monthlyAmount is preserved without ×100 (toCents() short-circuits).
	 *
	 * @return void
	 */
	public function testIntegerMonthlyAmountIsAlreadyCents(): void {
		$rows = [
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 200000],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15', self::ADMIN_A);

		self::assertSame(200000, $result['monthlyAmountCents']);

	}//end testIntegerMonthlyAmountIsAlreadyCents()

	/**
	 * ObjectService throwing → resolver swallows + logs + returns zero shape.
	 *
	 * @return void
	 */
	public function testObjectServiceFailureLogsErrorAndReturnsZeroShape(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR down'));

		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('RetainerResolver findAll failed'));
		// The "no active schedule" warning still fires after the empty list.
		$this->logger->expects(self::once())->method('warning');

		$svc = new RetainerResolver($this->container, $this->appConfig, $this->logger);
		$result = $svc->resolveRetainerAmount('sched-1', '2026-03-15', self::ADMIN_A);

		self::assertSame(0, $result['monthlyAmountCents']);

	}//end testObjectServiceFailureLogsErrorAndReturnsZeroShape()

	/**
	 * Overage fields default to null when absent on the record.
	 *
	 * @return void
	 */
	public function testMissingOverageFieldsDefaultToNull(): void {
		$rows = [
			['scheduleId' => 's', 'administrationId' => self::ADMIN_A, 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 1000],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15', self::ADMIN_A);

		self::assertNull($result['overageHoursThreshold']);
		self::assertNull($result['overageHourlyRateCents']);
		self::assertSame('Retainer', $result['label']);

	}//end testMissingOverageFieldsDefaultToNull()

	/**
	 * REQ-001 (ADR-005 Rule 3) — cross-tenant RetainerSchedule is unreachable.
	 *
	 * Administration A resolves a scheduleId that exists only under
	 * administration B. Before the fix the lookup filtered on `scheduleId`
	 * alone, so B's €5.000/month retainer (and its overage terms and label)
	 * were billed onto A's invoice. It must now resolve to the same zeroed
	 * schedule an unknown id yields, while B asking for its OWN schedule still
	 * gets the real amount — the positive control that proves the guard did
	 * not simply break the feature.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testCrossTenantScheduleIsNotResolved(): void {
		$rows = [
			[
				'scheduleId' => 'sched-victim',
				'administrationId' => self::ADMIN_B,
				'effectiveDate' => '2026-01-01',
				'monthlyAmount' => 5000.00,
				'overageHoursThreshold' => 10,
				'overageHourlyRate' => 250.00,
				'label' => 'Victim Confidential Retainer',
			],
		];

		$svc = $this->svcWithRows($rows);

		// Attacker (administration A) references administration B's schedule.
		$leaked = $svc->resolveRetainerAmount('sched-victim', '2026-03-15', self::ADMIN_A);

		self::assertSame(0, $leaked['monthlyAmountCents'], "administration B's retainer leaked into administration A");
		self::assertNull($leaked['overageHoursThreshold']);
		self::assertNull($leaked['overageHourlyRateCents']);
		self::assertSame('Retainer', $leaked['label']);
		self::assertNotSame('Victim Confidential Retainer', $leaked['label']);

		// Positive control: the owner still resolves its own schedule.
		$owned = $svc->resolveRetainerAmount('sched-victim', '2026-03-15', self::ADMIN_B);

		self::assertSame(500000, $owned['monthlyAmountCents']);
		self::assertSame(10.0, $owned['overageHoursThreshold']);
		self::assertSame(25000, $owned['overageHourlyRateCents']);
		self::assertSame('Victim Confidential Retainer', $owned['label']);

	}//end testCrossTenantScheduleIsNotResolved()

	/**
	 * REQ-001 — an empty administration scope fails closed.
	 *
	 * No register is read at all: an unscoped call can never be proven
	 * tenant-safe, so it yields the zeroed schedule rather than whatever the
	 * unfiltered query would have matched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testEmptyAdministrationScopeFailsClosed(): void {
		$rows = [
			[
				'scheduleId' => 'sched-victim',
				'administrationId' => self::ADMIN_B,
				'effectiveDate' => '2026-01-01',
				'monthlyAmount' => 5000.00,
				'label' => 'Victim Confidential Retainer',
			],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('sched-victim', '2026-03-15', '');

		self::assertSame(0, $result['monthlyAmountCents']);
		self::assertSame('Retainer', $result['label']);

	}//end testEmptyAdministrationScopeFailsClosed()

}//end class
