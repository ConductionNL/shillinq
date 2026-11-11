<?php

/**
 * Golden tests for VendorPerformanceAggregationJob: query, row shapes, calls and log lines.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the sweep's observable behaviour before it is split into steps.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class VendorPerformanceAggregationJobGoldenTest extends TestCase {

	/**
	 * Recorded calls, in order: log lines, lookups and aggregation calls.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Record a call.
	 *
	 * @param array<int,mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Run the job once.
	 *
	 * @param array<int,mixed>|\Throwable $rows      SupplierInvoice rows, or the lookup failure.
	 * @param \DateTime|\Throwable        $now       The current time, or the clock failure.
	 *
	 * @return void
	 */
	private function runJob(array|\Throwable $rows, \DateTime|\Throwable $now=new \DateTime('2024-03-10 12:00:00')): void {
		$test    = $this;
		$service = new class($rows, $test) {
			/**
			 * Construct.
			 *
			 * @param array<int,mixed>|\Throwable              $rows Rows or failure.
			 * @param VendorPerformanceAggregationJobGoldenTest $test Recorder.
			 */
			public function __construct(private array|\Throwable $rows, private VendorPerformanceAggregationJobGoldenTest $test) {
			}//end __construct()

			/**
			 * Fluent setter.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->record(call: ['setRegister', $register]);
				return $this;
			}//end setRegister()

			/**
			 * Fluent setter.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->record(call: ['setSchema', $schema]);
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $query Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $query): array {
				$this->test->record(call: ['findAll', $query]);
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end findAll()
		};

		$time = $this->createMock(ITimeFactory::class);
		if ($now instanceof \Throwable) {
			$time->method('getDateTime')->willThrowException($now);
		} else {
			$time->method('getDateTime')->willReturn($now);
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('reg-a');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$aggregation = $this->createMock(VendorPerformanceAggregation::class);
		$aggregation->method('aggregateAdministrationForPeriod')->willReturnCallback(
			function (string $administrationId, string $period): array {
				$this->calls[] = ['aggregate', $administrationId, $period];
				if ($administrationId === 'boom') {
					throw new \RuntimeException('engine down');
				}

				return array_fill(0, strlen($administrationId), ['id' => 's']);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['info', $message, $context];
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$job    = new VendorPerformanceAggregationJob($time, $settings, $aggregation, $container, $logger);
		$method = new ReflectionMethod(VendorPerformanceAggregationJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Row shapes, period bounds (leap February) and the call order of a sweep.
	 *
	 * @return void
	 */
	public function testSweepCallsAndSummary(): void {
		$serializable = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['administrationId' => ' boom ', 'invoiceDate' => '2024-02-01'];
			}//end jsonSerialize()
		};
		$scalarSerializable = new class implements \JsonSerializable {
			/**
			 * Serialise to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}//end jsonSerialize()
		};
		$rows = [
			['administrationId' => 'abc', 'invoiceDate' => '2024-02-29T23:59:59Z'],
			['administrationId' => 'late', 'invoiceDate' => '2024-03-01'],
			['administrationId' => 'early', 'invoiceDate' => '2024-01-31'],
			$serializable,
			$scalarSerializable,
			new \stdClass(),
			['administrationId' => 'abc', 'invoiceDate' => '2024-02-10'],
			['administrationId' => 'nodate'],
			['administrationId' => 'de', 'invoiceDate' => ' 2024-02-15 '],
		];
		$this->runJob(rows: $rows);

		self::assertSame(
			expected: [
				['setRegister', 'reg-a'],
				['setSchema', 'SupplierInvoice'],
				['findAll', ['filters' => []]],
				['aggregate', 'abc', '2024-02'],
				['aggregate', 'boom', '2024-02'],
				[
					'error',
					'VendorPerformanceAggregationJob: aggregation failed for administration',
					['administrationId' => 'boom', 'period' => '2024-02', 'exception' => 'engine down'],
				],
				['aggregate', 'de', '2024-02'],
				['info', 'VendorPerformanceAggregationJob: period=2024-02 scorecards=5 administrations=3 failed=1', []],
			],
			actual: $this->calls
		);
	}//end testSweepCallsAndSummary()

	/**
	 * A failed lookup logs the discovery error and stops.
	 *
	 * @return void
	 */
	public function testDiscoveryFailureLogged(): void {
		$this->runJob(rows: new \RuntimeException('register gone'));
		self::assertSame(
			expected: ['error', 'VendorPerformanceAggregationJob: failed to discover administrations', ['exception' => 'register gone']],
			actual: end($this->calls)
		);
		self::assertCount(expectedCount: 4, haystack: $this->calls);
	}//end testDiscoveryFailureLogged()

	/**
	 * A failing clock logs the period error and stops before any lookup.
	 *
	 * @return void
	 */
	public function testPeriodFailureLogged(): void {
		$this->runJob(rows: [], now: new \RuntimeException('no clock'));
		self::assertSame(
			expected: [['error', 'VendorPerformanceAggregationJob: failed to compute period', ['exception' => 'no clock']]],
			actual: $this->calls
		);
	}//end testPeriodFailureLogged()

	/**
	 * No activity still logs an all-zero summary.
	 *
	 * @return void
	 */
	public function testEmptySweepSummary(): void {
		$this->runJob(rows: [], now: new \DateTime('2025-01-31 23:00:00'));
		self::assertSame(
			expected: ['info', 'VendorPerformanceAggregationJob: period=2024-12 scorecards=0 administrations=0 failed=0', []],
			actual: end($this->calls)
		);
	}//end testEmptySweepSummary()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
