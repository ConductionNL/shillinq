<?php

/**
 * Golden tests for SoftCloseJob: the administration query, one run per administration and log lines.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\SoftCloseJob;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins one nightly run: what is read, which administrations are closed, and what is logged.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SoftCloseJobGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the job once.
	 *
	 * @param mixed                     $administrations findAll()'s answer, or a Throwable it throws.
	 * @param array<string,\Throwable>  $failures        Executor failures per administration id.
	 *
	 * @return void
	 */
	private function runJob(mixed $administrations, array $failures=[]): void {
		$store     = new class($this, $administrations) {
			/**
			 * Construct.
			 *
			 * @param SoftCloseJobGoldenTest $test            Recorder.
			 * @param mixed                  $administrations Answer.
			 */
			public function __construct(private SoftCloseJobGoldenTest $test, private mixed $administrations) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $params=[]): mixed {
				$this->test->calls[] = ['findAll', $params];
				if ($this->administrations instanceof \Throwable) {
					throw $this->administrations;
				}

				return $this->administrations;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$executor = $this->createMock(SoftCloseExecutor::class);
		$executor->method('execute')->willReturnCallback(
			function (string $administrationId, string $periodId, DateTimeImmutable $asOf) use ($failures): array {
				$this->calls[] = ['execute', $administrationId, $periodId];
				if (isset($failures[$administrationId]) === true) {
					throw $failures[$administrationId];
				}

				return ['status' => 'ok-' . $administrationId, 'postingCount' => strlen($administrationId), 'extra' => 1];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$job    = new SoftCloseJob($this->createMock(ITimeFactory::class), $executor, $container, $appConfig, $logger);
		$method = new ReflectionMethod(SoftCloseJob::class, 'runAsJob');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Each active administration with an id is closed for the current month; a failure does not stop the rest.
	 *
	 * @return void
	 */
	public function testClosesEachAdministration(): void {
		$entity = new \stdClass();
		$entity->administrationId = 'a3';
		$administrations = ['k' => ['id' => 'a1'], ['administrationId' => 'a2'], $entity, ['name' => 'no id'], ['id' => '', 'administrationId' => 'a4']];
		$this->runJob(administrations: $administrations, failures: ['a2' => new \RuntimeException('locked')]);
		$period = (new DateTimeImmutable())->format('Y-m');
		self::assertSame(
			expected: [
				['setRegister', 'shillinq'],
				['setSchema', 'Administration'],
				['findAll', ['filters' => ['lifecycleState' => 'active']]],
				['execute', 'a1', $period],
				[
					'info',
					'SoftCloseJob: soft-close run completed',
					['administrationId' => 'a1', 'periodId' => $period, 'status' => 'ok-a1', 'postingCount' => 2,
				]],
				['execute', 'a2', $period],
				['error', 'SoftCloseJob: soft-close run failed', ['administrationId' => 'a2', 'periodId' => $period, 'exception' => 'locked']],
				['execute', 'a3', $period],
				[
					'info',
					'SoftCloseJob: soft-close run completed',
					['administrationId' => 'a3', 'periodId' => $period, 'status' => 'ok-a3', 'postingCount' => 2,
				]],
			],
			actual: $this->calls
		);
	}//end testClosesEachAdministration()

	/**
	 * A non-array answer closes nothing; a failing lookup logs the enumeration error and closes nothing.
	 *
	 * @return void
	 */
	public function testNothingToClose(): void {
		$this->runJob(administrations: 'nope');
		$actual = array_values(array_filter($this->calls, fn (array $c): bool => in_array($c[0], ['execute', 'info', 'error'], true)));
		self::assertSame(expected: [], actual: $actual);
		$this->calls = [];
		$this->runJob(administrations: new \RuntimeException('db'));
		self::assertSame(expected: ['error', 'SoftCloseJob: failed to enumerate administrations', ['exception' => 'db']], actual: end($this->calls));
	}//end testNothingToClose()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
