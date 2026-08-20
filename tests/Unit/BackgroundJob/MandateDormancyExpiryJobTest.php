<?php

/**
 * Unit tests for MandateDormancyExpiryJob.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\BackgroundJob\MandateDormancyExpiryJob;
use OCA\Shillinq\Lifecycle\MandateGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the daily SEPA-mandate dormancy expiry job (REQ-SDD-008).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MandateDormancyExpiryJobTest extends TestCase {
	/**
	 * Mock DI container.
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
	 * Mock dormancy guard.
	 *
	 * @var MandateGuard&MockObject
	 */
	private MandateGuard&MockObject $guard;

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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->guard = $this->createMock(MandateGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return MandateDormancyExpiryJob
	 */
	private function makeJob(): MandateDormancyExpiryJob {
		return new MandateDormancyExpiryJob(
			$this->createMock(ITimeFactory::class),
			$this->container,
			$this->appConfig,
			$this->guard,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param MandateDormancyExpiryJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(MandateDormancyExpiryJob $job): void {
		$m = new ReflectionMethod(MandateDormancyExpiryJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * A non-array mandate row is skipped defensively — the guard is never
	 * consulted for it.
	 *
	 * @return void
	 */
	public function testRunSkipsNonArrayMandate(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->guard->expects(self::never())->method('canExpire');

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [new \stdClass()];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsNonArrayMandate()

	/**
	 * A mandate the guard refuses to expire is left untouched — no write.
	 *
	 * @return void
	 */
	public function testRunLeavesMandateUntouchedWhenGuardDenies(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->guard->method('canExpire')->willReturn(false);

		$objectService = new class {
			public int $saved = 0;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [['id' => 'mandate-1', 'status' => 'active']];
			}
			public function saveObject(array $object): void {
				$this->saved++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		self::assertSame(0, $objectService->saved, 'A guard refusal must not write a status change');
	}//end testRunLeavesMandateUntouchedWhenGuardDenies()

	/**
	 * A mandate the guard allows to expire is written back with status=expired.
	 *
	 * @return void
	 */
	public function testRunExpiresMandateWhenGuardAllows(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->guard->method('canExpire')->willReturn(true);

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
				return [['id' => 'mandate-1', 'status' => 'active']];
			}
			public function saveObject(array $object): void {
				$this->saved++;
				$this->lastObject = $object;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('expired 1 dormant SEPA mandate'));

		$this->invokeRun($this->makeJob());
		self::assertSame(1, $objectService->saved);
		self::assertSame('expired', $objectService->lastObject['status']);
	}//end testRunExpiresMandateWhenGuardAllows()

	/**
	 * A container resolution failure is caught and logged rather than
	 * crashing the scheduler.
	 *
	 * @return void
	 */
	public function testRunCatchesContainerException(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('MandateDormancyExpiryJob: run failed'), self::anything());

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesContainerException()

	/**
	 * The register slug falls back to 'shillinq' when app config is empty.
	 *
	 * @return void
	 */
	public function testResolveRegisterDefaultsWhenConfigEmpty(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'register', 'shillinq')
			->willReturn('');

		$job = $this->makeJob();
		$m = new ReflectionMethod(MandateDormancyExpiryJob::class, 'resolveRegister');
		$m->setAccessible(true);

		self::assertSame('shillinq', $m->invoke($job));
	}//end testResolveRegisterDefaultsWhenConfigEmpty()

	/**
	 * The register slug honours an operator-configured override.
	 *
	 * @return void
	 */
	public function testResolveRegisterHonoursConfiguredValue(): void {
		$this->appConfig->method('getValueString')->willReturn('custom-register');

		$job = $this->makeJob();
		$m = new ReflectionMethod(MandateDormancyExpiryJob::class, 'resolveRegister');
		$m->setAccessible(true);

		self::assertSame('custom-register', $m->invoke($job));
	}//end testResolveRegisterHonoursConfiguredValue()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
