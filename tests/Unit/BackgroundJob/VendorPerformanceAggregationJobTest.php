<?php

/**
 * Unit tests for VendorPerformanceAggregationJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\VendorPerformanceAggregationJob;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the monthly vendor-performance aggregation sweep
 * (REQ-PO3W-008 / REQ-VP-001 / REQ-VP-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VendorPerformanceAggregationJobTest extends TestCase {
	/**
	 * Mock time factory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $timeFactory;

	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock aggregation service.
	 *
	 * @var VendorPerformanceAggregation&MockObject
	 */
	private VendorPerformanceAggregation&MockObject $aggregation;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-06-15'));
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->aggregation = $this->createMock(VendorPerformanceAggregation::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return VendorPerformanceAggregationJob
	 */
	private function makeJob(): VendorPerformanceAggregationJob {
		return new VendorPerformanceAggregationJob(
			$this->timeFactory,
			$this->settings,
			$this->aggregation,
			$this->container,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param VendorPerformanceAggregationJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(VendorPerformanceAggregationJob $job): void {
		$m = new ReflectionMethod(VendorPerformanceAggregationJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * Invoke a private method on the job under test.
	 *
	 * @param VendorPerformanceAggregationJob $job The job.
	 * @param string $name The method name.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function invoke(VendorPerformanceAggregationJob $job, string $name, array $args): mixed {
		$m = new ReflectionMethod(VendorPerformanceAggregationJob::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($job, $args);
	}//end invoke()

	/**
	 * run() skips entirely when OpenRegister is unavailable — the aggregation
	 * service is never invoked.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');
		$this->aggregation->expects(self::never())->method('aggregateAdministrationForPeriod');

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * previousMonthPeriod() returns the YYYY-MM code of the calendar month
	 * before the supplied date, regardless of day-of-month.
	 *
	 * @return void
	 */
	public function testPreviousMonthPeriodComputesPriorMonth(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$job = $this->makeJob();

		self::assertSame('2026-05', $this->invoke($job, 'previousMonthPeriod', [new \DateTime('2026-06-15')]));
		self::assertSame('2025-12', $this->invoke($job, 'previousMonthPeriod', [new \DateTime('2026-01-01')]));
	}//end testPreviousMonthPeriodComputesPriorMonth()

	/**
	 * dateOnly() reduces an ISO timestamp to its date component and passes a
	 * shorter string through unchanged.
	 *
	 * @return void
	 */
	public function testDateOnlyTruncatesIsoTimestamp(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$job = $this->makeJob();

		self::assertSame('2026-05-10', $this->invoke($job, 'dateOnly', ['2026-05-10T12:00:00Z']));
		self::assertSame('2026-05', $this->invoke($job, 'dateOnly', ['2026-05']));
	}//end testDateOnlyTruncatesIsoTimestamp()

	/**
	 * discoverAdministrationsForPeriod() rejects a malformed period code
	 * before ever resolving the container.
	 *
	 * @return void
	 */
	public function testDiscoverAdministrationsForPeriodRejectsMalformedPeriod(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->expects(self::never())->method('get');

		$job = $this->makeJob();
		self::assertSame([], $this->invoke($job, 'discoverAdministrationsForPeriod', ['2026-13']));
	}//end testDiscoverAdministrationsForPeriodRejectsMalformedPeriod()

	/**
	 * discoverAdministrationsForPeriod() keeps only rows whose invoiceDate
	 * falls inside the period, and de-duplicates administrationId.
	 *
	 * @return void
	 */
	public function testDiscoverAdministrationsForPeriodFiltersAndDedupes(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [
					['administrationId' => 'adm-1', 'invoiceDate' => '2026-05-05T10:00:00Z'],
					['administrationId' => 'adm-1', 'invoiceDate' => '2026-05-20'],
					['administrationId' => 'adm-2', 'invoiceDate' => '2026-04-30'],
					['administrationId' => '', 'invoiceDate' => '2026-05-05'],
					['administrationId' => 'adm-3'],
				];
			}
		};
		$this->container->method('get')->willReturn($objectService);

		$job = $this->makeJob();
		$result = $this->invoke($job, 'discoverAdministrationsForPeriod', ['2026-05']);

		self::assertSame(['adm-1'], $result);
	}//end testDiscoverAdministrationsForPeriodFiltersAndDedupes()

	/**
	 * run() aggregates every discovered administration and totals the
	 * resulting scorecards into the summary log line.
	 *
	 * @return void
	 */
	public function testRunAggregatesDiscoveredAdministrations(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [
					['administrationId' => 'adm-1', 'invoiceDate' => '2026-05-05'],
					['administrationId' => 'adm-2', 'invoiceDate' => '2026-05-06'],
				];
			}
		};
		$this->container->method('get')->willReturn($objectService);

		$this->aggregation->method('aggregateAdministrationForPeriod')
			->willReturnCallback(static function (string $administrationId, string $period): array {
				return ($administrationId === 'adm-1' ? [['id' => 's1'], ['id' => 's2']] : [['id' => 's3']]);
			});

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('period=2026-05 scorecards=3 administrations=2 failed=0'));

		$this->invokeRun($this->makeJob());
	}//end testRunAggregatesDiscoveredAdministrations()

	/**
	 * A single administration's aggregation failure is logged and counted
	 * without aborting the sweep for the remaining administrations.
	 *
	 * @return void
	 */
	public function testRunCountsFailedAggregationAndContinues(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [
					['administrationId' => 'adm-fail', 'invoiceDate' => '2026-05-05'],
					['administrationId' => 'adm-ok', 'invoiceDate' => '2026-05-06'],
				];
			}
		};
		$this->container->method('get')->willReturn($objectService);

		$this->aggregation->method('aggregateAdministrationForPeriod')
			->willReturnCallback(static function (string $administrationId, string $period): array {
				if ($administrationId === 'adm-fail') {
					throw new \RuntimeException('scoring engine unavailable');
				}
				return [['id' => 's1']];
			});

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('aggregation failed for administration'), self::anything());
		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('scorecards=1 administrations=2 failed=1'));

		$this->invokeRun($this->makeJob());
	}//end testRunCountsFailedAggregationAndContinues()

	/**
	 * A container resolution failure during discovery is caught and logged;
	 * the aggregation service is never reached.
	 *
	 * @return void
	 */
	public function testRunCatchesDiscoveryException(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
		$this->aggregation->expects(self::never())->method('aggregateAdministrationForPeriod');

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('failed to discover administrations'), self::anything());

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesDiscoveryException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
