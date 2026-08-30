<?php

/**
 * Unit tests for CalibrationBatchJob.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\CalibrationBatchJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the monthly calibration report orchestrator (REQ-CF-013).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CalibrationBatchJobTest extends TestCase {
	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

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
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return CalibrationBatchJob
	 */
	private function makeJob(): CalibrationBatchJob {
		return new CalibrationBatchJob(
			$this->createMock(ITimeFactory::class),
			$this->settings,
			$this->container,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param CalibrationBatchJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(CalibrationBatchJob $job): void {
		$m = new ReflectionMethod(CalibrationBatchJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * run() skips entirely when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * A horizon missing horizonId or administrationId is skipped without a
	 * report-existence lookup.
	 *
	 * @return void
	 */
	public function testRunSkipsHorizonMissingIdentifiers(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = new class {
			public int $reportLookups = 0;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				if (array_key_exists('reportId', ($opts['filters'] ?? []))) {
					$this->reportLookups++;
					return [];
				}
				return [['administrationId' => 'adm-1']];
			}
			public function saveObject(array $object): void {
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(0, $objectService->reportLookups, 'A horizon without horizonId must never reach the report lookup');
	}//end testRunSkipsHorizonMissingIdentifiers()

	/**
	 * An already-existing calibration report for the period is idempotent —
	 * no second save.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenReportAlreadyExists(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = new class {
			public int $saved = 0;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				if (array_key_exists('reportId', ($opts['filters'] ?? []))) {
					return [['reportId' => 'calib-h1-existing']];
				}
				return [['horizonId' => 'h1', 'administrationId' => 'adm-1']];
			}
			public function saveObject(array $object): void {
				$this->saved++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(0, $objectService->saved, 'An existing report for the period must not be recreated');
	}//end testRunSkipsWhenReportAlreadyExists()

	/**
	 * A new horizon with no prior report gets a fresh calibration envelope.
	 *
	 * @return void
	 */
	public function testRunCreatesReportForNewHorizon(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = new class {
			public int $saved = 0;
			public ?array $lastObject = null;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				if (array_key_exists('reportId', ($opts['filters'] ?? []))) {
					return [];
				}
				return [['horizonId' => 'h1', 'administrationId' => 'adm-1']];
			}
			public function saveObject(array $object): void {
				$this->saved++;
				$this->lastObject = $object;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(1, $objectService->saved);
		self::assertSame('h1', $objectService->lastObject['horizonId']);
		self::assertSame('adm-1', $objectService->lastObject['administrationId']);
	}//end testRunCreatesReportForNewHorizon()

	/**
	 * A container resolution failure is caught and logged rather than
	 * crashing the scheduler.
	 *
	 * @return void
	 */
	public function testRunCatchesContainerException(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('CalibrationBatchJob failed'));

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesContainerException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
