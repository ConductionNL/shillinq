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
use OCA\Shillinq\Service\SoftCloseSwingCheck;
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
	 * Mock swing check.
	 *
	 * @var SoftCloseSwingCheck&MockObject
	 */
	private SoftCloseSwingCheck&MockObject $swingCheck;

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
		$this->swingCheck = $this->createMock(SoftCloseSwingCheck::class);
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
			$this->swingCheck,
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

	/**
	 * The object service double that lists one active administration.
	 *
	 * @return object
	 */
	private function oneAdministration(): object {
		return new class {
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
	}//end oneAdministration()

	/**
	 * A completed soft close is followed by the swing check for the same administration and period (REQ-CLS-011).
	 *
	 * @return void
	 */
	public function testACompletedSoftCloseRunsTheSwingCheck(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->container->method('get')->willReturn($this->oneAdministration());
		$this->executor->method('execute')->willReturn(['status' => 'completed', 'postingCount' => 3]);

		$period = (new \DateTimeImmutable())->format('Y-m');
		$this->swingCheck->expects(self::once())
			->method('run')
			->with('adm-1', $period, self::isInstanceOf(\DateTimeImmutable::class))
			->willReturn(['materialCount' => 2]);

		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$infos): void {
				$infos[] = [$message, $context];
			}
		);

		$this->invokeRun($this->makeJob());

		self::assertSame('SoftCloseJob: swing check completed', $infos[1][0]);
		self::assertSame(['administrationId' => 'adm-1', 'periodId' => $period, 'materialCount' => 2], $infos[1][1]);
	}//end testACompletedSoftCloseRunsTheSwingCheck()

	/**
	 * A soft close that did not complete, or that threw, runs no swing check.
	 *
	 * @return void
	 */
	public function testAFailedSoftCloseRunsNoSwingCheck(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->container->method('get')->willReturn($this->oneAdministration());
		$this->executor->method('execute')->willReturnOnConsecutiveCalls(['status' => 'failed', 'postingCount' => 0]);
		$this->swingCheck->expects(self::never())->method('run');

		$this->invokeRun($this->makeJob());

		$this->executor = $this->createMock(SoftCloseExecutor::class);
		$this->executor->method('execute')->willThrowException(new \RuntimeException('period locked'));
		$this->invokeRun($this->makeJob());
	}//end testAFailedSoftCloseRunsNoSwingCheck()

	/**
	 * A failing swing check is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSwingCheckIsLogged(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->container->method('get')->willReturn($this->oneAdministration());
		$this->executor->method('execute')->willReturn(['status' => 'completed', 'postingCount' => 0]);
		$this->swingCheck->method('run')->willThrowException(new \RuntimeException('trial balance down'));

		$this->logger->expects(self::once())
			->method('error')
			->with('SoftCloseJob: swing check failed', self::callback(
				static fn (array $ctx): bool => $ctx['administrationId'] === 'adm-1' && $ctx['exception'] === 'trial balance down'
			));

		$this->invokeRun($this->makeJob());
	}//end testAFailingSwingCheckIsLogged()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
