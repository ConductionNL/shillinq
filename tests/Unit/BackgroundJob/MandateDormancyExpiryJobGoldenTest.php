<?php

/**
 * Golden tests for MandateDormancyExpiryJob: the mandate query, the expiry saves and log lines.
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

use OCA\Shillinq\BackgroundJob\MandateDormancyExpiryJob;
use OCA\Shillinq\Lifecycle\MandateGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins one run: what is read, which mandates are expired, in what order, and what is logged.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class MandateDormancyExpiryJobGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the job once.
	 *
	 * @param mixed               $mandates  findAll()'s answer, or a Throwable it throws.
	 * @param array<string,bool>  $expirable canExpire() per mandate id.
	 * @param \Throwable|null     $saveFails What saveObject throws, if anything.
	 *
	 * @return void
	 */
	private function runJob(mixed $mandates, array $expirable=[], ?\Throwable $saveFails=null): void {
		$store     = new class($this, $mandates, $saveFails) {
			/**
			 * Construct.
			 *
			 * @param MandateDormancyExpiryJobGoldenTest $test      Recorder.
			 * @param mixed                              $mandates  Answer.
			 * @param \Throwable|null                    $saveFails Save failure.
			 */
			public function __construct(private MandateDormancyExpiryJobGoldenTest $test, private mixed $mandates, private ?\Throwable $saveFails) {
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
				if ($this->mandates instanceof \Throwable) {
					throw $this->mandates;
				}

				return $this->mandates;
			}//end findAll()

			/**
			 * Record.
			 *
			 * @param array<string,mixed> $object The mandate.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->test->calls[] = ['saveObject', $object];
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				return $object;
			}//end saveObject()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store): object {
				$this->calls[] = ['get', $id];
				return $store;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (): string {
				$this->calls[] = ['config'];
				return 'reg-k';
			}
		);
		$guard = $this->createMock(MandateGuard::class);
		$guard->method('canExpire')->willReturnCallback(
			function (string $mandateId, ?array $object=null) use ($expirable): bool {
				$this->calls[] = ['canExpire', $mandateId];
				return ($expirable[$mandateId] ?? false);
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

		$job    = new MandateDormancyExpiryJob($this->createMock(ITimeFactory::class), $container, $appConfig, $guard, $logger);
		$method = new ReflectionMethod(MandateDormancyExpiryJob::class, 'runAsJob');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Dormant active mandates are expired one by one, re-scoped before each save, then counted.
	 *
	 * @return void
	 */
	public function testExpiresDormantMandates(): void {
		$mandates = [['id' => 'm1', 'status' => 'active'], 'junk', ['status' => 'active'], ['id' => 'm3', 'status' => 'active', 'x' => 1]];
		$this->runJob(mandates: $mandates, expirable: ['m1' => true, '' => false, 'm3' => true]);
		self::assertSame(
			expected: [
				['config'],
				['get', 'OCA\OpenRegister\Service\ObjectService'],
				['setRegister', 'reg-k'],
				['setSchema', 'SepaMandate'],
				['findAll', ['filters' => ['status' => 'active']]],
				['canExpire', 'm1'],
				['setRegister', 'reg-k'],
				['setSchema', 'SepaMandate'],
				['saveObject', ['id' => 'm1', 'status' => 'expired']],
				['canExpire', ''],
				['canExpire', 'm3'],
				['setRegister', 'reg-k'],
				['setSchema', 'SepaMandate'],
				['saveObject', ['id' => 'm3', 'status' => 'expired', 'x' => 1]],
				['info', 'MandateDormancyExpiryJob: expired 2 dormant SEPA mandate(s) (REQ-SDD-008)', []],
			],
			actual: $this->calls
		);
	}//end testExpiresDormantMandates()

	/**
	 * Nothing to expire logs nothing; a failing read or save logs the run failure.
	 *
	 * @return void
	 */
	public function testQuietAndFailingRuns(): void {
		$this->runJob(mandates: [['id' => 'm1']]);
		$actual = array_values(array_filter($this->calls, fn (array $c): bool => in_array($c[0], ['info', 'error'], true)));
		self::assertSame(expected: [], actual: $actual);
		$this->calls = [];
		$this->runJob(mandates: new \RuntimeException('db'));
		self::assertSame(expected: ['error', 'MandateDormancyExpiryJob: run failed', ['exception' => 'db']], actual: end($this->calls));
		$this->calls = [];
		$this->runJob(mandates: [['id' => 'm1'], ['id' => 'm2']], expirable: ['m1' => true, 'm2' => true], saveFails: new \RuntimeException('save'));
		self::assertSame(expected: ['saveObject', ['id' => 'm1', 'status' => 'expired']], actual: $this->calls[count($this->calls) - 2]);
		self::assertSame(expected: ['error', 'MandateDormancyExpiryJob: run failed', ['exception' => 'save']], actual: end($this->calls));
	}//end testQuietAndFailingRuns()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
