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
 * money inputs.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 */
final class RetainerResolverTest extends TestCase {

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
	 * Build the subject + wire a fluent fake ObjectService returning $rows
	 * regardless of the filter argument.
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
			 * @param array<string,mixed> $config Find configuration (ignored).
			 * @return array<int, array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				return $this->rows;
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
		$result = $svc->resolveRetainerAmount('sched-1', '2026-03-15');

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
				'effectiveDate' => '2026-01-01',
				'monthlyAmount' => 1234.56,
				'overageHoursThreshold' => 40,
				'overageHourlyRate' => 125.00,
				'label' => 'Premium Retainer',
			],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('sched-1', '2026-03-15');

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
			['scheduleId' => 's', 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 10.00],
			['scheduleId' => 's', 'effectiveDate' => '2026-02-01', 'monthlyAmount' => 15.00],
			['scheduleId' => 's', 'effectiveDate' => '2025-12-01', 'monthlyAmount' => 9.00],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15');

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
			['scheduleId' => 's', 'effectiveDate' => '2026-04-01', 'monthlyAmount' => 9999],
		];

		$this->logger->expects(self::once())->method('warning');

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15');

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
				'effectiveDate' => '2026-01-01',
				'endDate' => '2026-02-28',
				'monthlyAmount' => 9999,
			],
		];

		$this->logger->expects(self::once())->method('warning');

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15');

		self::assertSame(0, $result['monthlyAmountCents']);

	}//end testExpiredEndDateIsSkipped()

	/**
	 * Integer monthlyAmount is preserved without ×100 (toCents() short-circuits).
	 *
	 * @return void
	 */
	public function testIntegerMonthlyAmountIsAlreadyCents(): void {
		$rows = [
			['scheduleId' => 's', 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 200000],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15');

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
		$result = $svc->resolveRetainerAmount('sched-1', '2026-03-15');

		self::assertSame(0, $result['monthlyAmountCents']);

	}//end testObjectServiceFailureLogsErrorAndReturnsZeroShape()

	/**
	 * Overage fields default to null when absent on the record.
	 *
	 * @return void
	 */
	public function testMissingOverageFieldsDefaultToNull(): void {
		$rows = [
			['scheduleId' => 's', 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 1000],
		];

		$result = $this->svcWithRows($rows)->resolveRetainerAmount('s', '2026-03-15');

		self::assertNull($result['overageHoursThreshold']);
		self::assertNull($result['overageHourlyRateCents']);
		self::assertSame('Retainer', $result['label']);

	}//end testMissingOverageFieldsDefaultToNull()

}//end class
