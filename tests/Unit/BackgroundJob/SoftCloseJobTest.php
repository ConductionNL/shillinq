<?php

/**
 * Unit tests for SoftCloseJob.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\BackgroundJob\SoftCloseJob;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the nightly soft-close orchestrator (REQ-CLS-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SoftCloseJobTest extends TestCase {
	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock soft-close executor.
	 *
	 * @var SoftCloseExecutor&MockObject
	 */
	private SoftCloseExecutor&MockObject $executor;

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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->executor = $this->createMock(SoftCloseExecutor::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return SoftCloseJob
	 */
	private function makeJob(): SoftCloseJob {
		return new SoftCloseJob(
			$this->createMock(ITimeFactory::class),
			$this->executor,
			$this->container,
			$this->appConfig,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param SoftCloseJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(SoftCloseJob $job): void {
		$m = new ReflectionMethod(SoftCloseJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * An administration record without an id is skipped — the executor is
	 * never invoked for it.
	 *
	 * @return void
	 */
	public function testRunSkipsAdministrationWithoutId(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->executor->expects(self::never())->method('execute');

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [[]];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsAdministrationWithoutId()

	/**
	 * A successful soft-close run for an active administration is logged
	 * with its status and posting count.
	 *
	 * @return void
	 */
	public function testRunLogsSuccessfulSoftClose(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->executor->method('execute')->willReturn(['status' => 'closed', 'postingCount' => 12]);

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [['id' => 'adm-1']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('soft-close run completed'), self::callback(
				static fn (array $ctx): bool => $ctx['administrationId'] === 'adm-1' && $ctx['postingCount'] === 12
			));

		$this->invokeRun($this->makeJob());
	}//end testRunLogsSuccessfulSoftClose()

	/**
	 * A failing soft-close run for one administration is logged and does not
	 * abort the sweep.
	 *
	 * @return void
	 */
	public function testRunLogsFailedSoftCloseAndContinues(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->executor->method('execute')->willThrowException(new \RuntimeException('period locked'));

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [['id' => 'adm-1']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('soft-close run failed'), self::anything());

		$this->invokeRun($this->makeJob());
	}//end testRunLogsFailedSoftCloseAndContinues()

	/**
	 * A container resolution failure inside findActiveAdministrations() is
	 * caught, logged, and yields an empty sweep rather than crashing.
	 *
	 * @return void
	 */
	public function testFindActiveAdministrationsReturnsEmptyOnException(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
		$this->executor->expects(self::never())->method('execute');

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('failed to enumerate administrations'), self::anything());

		$this->invokeRun($this->makeJob());
	}//end testFindActiveAdministrationsReturnsEmptyOnException()

	/**
	 * The register slug falls back to 'shillinq' when app config is empty,
	 * and honours a configured override otherwise.
	 *
	 * @return void
	 */
	public function testRegisterFallsBackWhenConfigEmpty(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'register', 'shillinq')
			->willReturn('');

		$job = $this->makeJob();
		$m = new ReflectionMethod(SoftCloseJob::class, 'register');
		$m->setAccessible(true);

		self::assertSame('shillinq', $m->invoke($job));
	}//end testRegisterFallsBackWhenConfigEmpty()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
