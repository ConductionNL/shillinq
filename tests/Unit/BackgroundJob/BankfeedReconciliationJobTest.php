<?php

/**
 * Unit tests for BankfeedReconciliationJob.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\BankfeedReconciliationJob;
use OCA\Shillinq\Service\BankfeedMatcher;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the daily bankfeed pull + reconciliation orchestrator (REQ-CF-012).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BankfeedReconciliationJobTest extends TestCase {
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
	 * Mock bankfeed matcher.
	 *
	 * @var BankfeedMatcher&MockObject
	 */
	private BankfeedMatcher&MockObject $matcher;

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
		$this->matcher = $this->createMock(BankfeedMatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return BankfeedReconciliationJob
	 */
	private function makeJob(): BankfeedReconciliationJob {
		return new BankfeedReconciliationJob(
			$this->createMock(ITimeFactory::class),
			$this->settings,
			$this->matcher,
			$this->container,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param BankfeedReconciliationJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(BankfeedReconciliationJob $job): void {
		$m = new ReflectionMethod(BankfeedReconciliationJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * run() skips entirely when OpenRegister is unavailable — the container
	 * is never resolved.
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
	 * A bank connection missing an IBAN is skipped before any statement or
	 * candidate lookup, and the matcher is never invoked.
	 *
	 * @return void
	 */
	public function testRunSkipsConnectionWithoutIban(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->matcher->expects(self::never())->method('matchTransaction');

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				if (($opts['filters']['lifecycleState'] ?? null) === 'active') {
					return [['administrationId' => 'adm-1']];
				}
				return [];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsConnectionWithoutIban()

	/**
	 * A high-confidence match with a resolved AR invoice counts as reconciled;
	 * a low-confidence match counts as unmatched.
	 *
	 * @return void
	 */
	public function testRunCountsReconciledAndUnmatchedStatements(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$this->matcher->method('matchTransaction')->willReturnOnConsecutiveCalls(
			['confidence' => 0.95, 'arInvoiceId' => 'ar-1'],
			['confidence' => 0.10, 'arInvoiceId' => null],
		);

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				$schema = ($opts['filters']['bankAccountIban'] ?? null);
				if ($schema !== null) {
					return [['id' => 'st-1'], ['id' => 'st-2']];
				}
				if (($opts['filters']['administrationId'] ?? null) === 'adm-1'
					&& array_key_exists('lifecycleState', $opts['filters']) === false
				) {
					return [['id' => 'ar-1']];
				}
				return [['bankAccountIban' => 'NL01BANK1234567890', 'administrationId' => 'adm-1']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('1 reconciled, 1 unmatched'));

		$this->invokeRun($this->makeJob());
	}//end testRunCountsReconciledAndUnmatchedStatements()

	/**
	 * A container resolution failure is caught and logged rather than crashing
	 * the scheduler.
	 *
	 * @return void
	 */
	public function testRunCatchesContainerException(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('BankfeedReconciliationJob failed'));

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesContainerException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
