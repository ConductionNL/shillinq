<?php

/**
 * Unit tests for CrisisModeRefreshJob.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\CrisisModeRefreshJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the daily crisis-mode refresh orchestrator (REQ-CF-010).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CrisisModeRefreshJobTest extends TestCase {
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
	 * @return CrisisModeRefreshJob
	 */
	private function makeJob(): CrisisModeRefreshJob {
		return new CrisisModeRefreshJob(
			$this->createMock(ITimeFactory::class),
			$this->settings,
			$this->container,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param CrisisModeRefreshJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(CrisisModeRefreshJob $job): void {
		$m = new ReflectionMethod(CrisisModeRefreshJob::class, 'run');
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
	 * A horizon with every leading week's closingBalance positive is left
	 * untouched — no rolledOn write.
	 *
	 * @return void
	 */
	public function testRunLeavesHealthyHorizonUntouched(): void {
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
				if (array_key_exists('horizonId', ($opts['filters'] ?? []))) {
					return [
						['closingBalance' => 1200.0],
						['closingBalance' => 800.0],
					];
				}
				return [['horizonId' => 'h1']];
			}
			public function saveObject(array $object): void {
				$this->saved++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(0, $objectService->saved, 'A horizon with no negative leading week must not be rolled');
	}//end testRunLeavesHealthyHorizonUntouched()

	/**
	 * A horizon with a negative closingBalance in its leading weeks is
	 * re-rolled — one saveObject with a fresh rolledOn timestamp.
	 *
	 * @return void
	 */
	public function testRunRefreshesHorizonWithNegativeBalance(): void {
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
				if (array_key_exists('horizonId', ($opts['filters'] ?? []))) {
					return [
						['closingBalance' => 500.0],
						['closingBalance' => -150.0],
					];
				}
				return [['horizonId' => 'h1']];
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
		self::assertArrayHasKey('rolledOn', $objectService->lastObject);
	}//end testRunRefreshesHorizonWithNegativeBalance()

	/**
	 * A horizon record missing horizonId is skipped before the weeks lookup.
	 *
	 * @return void
	 */
	public function testRunSkipsHorizonWithoutId(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = new class {
			public int $weekLookups = 0;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				if (array_key_exists('horizonId', ($opts['filters'] ?? []))) {
					$this->weekLookups++;
					return [];
				}
				return [[]];
			}
			public function saveObject(array $object): void {
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(0, $objectService->weekLookups, 'A horizon without an id must never reach the weeks lookup');
	}//end testRunSkipsHorizonWithoutId()

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
			->with(self::stringContains('CrisisModeRefreshJob failed'));

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesContainerException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
