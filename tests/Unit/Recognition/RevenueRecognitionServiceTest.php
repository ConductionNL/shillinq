<?php

/**
 * Unit tests for RevenueRecognitionService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Recognition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Recognition;

use OCA\Shillinq\Service\RevenueRecognitionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the recognized-recurring-revenue arithmetic (order-revenue-recognition-engine).
 *
 * Covers the whole-month overlap proration + frequency normalization fold over
 * RECURRING lines, the separate one-off recognition (point-in-time in/out of period),
 * the ARR run-rate, the empty-input clean zero, and the fail-closed null-frequentie
 * line. The ObjectService read is stubbed so each case feeds fixed SalesOrder +
 * SalesOrderLine arrays.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RevenueRecognitionServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
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
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service with an ObjectService stub returning the given data sets.
	 *
	 * @param array<int,array<string,mixed>> $orders SalesOrder records.
	 * @param array<int,array<string,mixed>> $lines SalesOrderLine records.
	 *
	 * @return RevenueRecognitionService
	 */
	private function buildService(array $orders, array $lines): RevenueRecognitionService {
		$stub = new class($orders, $lines) {

			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Last schema selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $orders SalesOrder records.
			 * @param array<int,array<string,mixed>> $lines SalesOrderLine records.
			 */
			public function __construct(array $orders, array $lines) {
				$this->data = [
					'SalesOrder' => $orders,
					'SalesOrderLine' => $lines,
				];
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter; records the active schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the data set for the active schema, applying simple equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		// Create a fresh container mock per invocation so a second buildService()
		// call within the same test method gets its own clean stub rather than
		// stacking willReturn() on the already-configured shared mock.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		return new RevenueRecognitionService(
			container: $container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end buildService()

	/**
	 * The seed order's recurring lines recognize 7500 over Q1 with ARR 30000.
	 *
	 * Line A JAARLIJKS 12000 (monthlyRate 1000) + Line C MAANDELIJKS 1500, term
	 * [2026-01-01, 2026-12-31], period [2026-01-01, 2026-03-31] → 3000 + 4500 = 7500.
	 *
	 * @return void
	 */
	public function testFullMonthRecurringSeedSample(): void {
		$orders = [
			[
				'orderId' => 'ORDER-2026-0001',
				'administrationId' => 'adm-1',
				'currency' => 'EUR',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
			],
		];
		$lines = [
			[
				'lineId' => 'A',
				'orderId' => 'ORDER-2026-0001',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'ANNUALLY',
				'amount' => 12000.0,
			],
			[
				'lineId' => 'C',
				'orderId' => 'ORDER-2026-0001',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'MONTHLY',
				'amount' => 1500.0,
			],
		];

		$result = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(7500.0, $result['recognized']);
		self::assertSame(30000.0, $result['arr']);
		self::assertSame('EUR', $result['currency']);
		self::assertSame(2, $result['lineCount']);
		self::assertSame(0.0, $result['oneOff']);

	}//end testFullMonthRecurringSeedSample()

	/**
	 * A mid-month term start still counts the whole month (D5 whole-month rounding).
	 *
	 * MAANDELIJKS 1000 line, term [2026-01-15, 2026-12-31], period
	 * [2026-01-01, 2026-03-31] → 3000 (Jan, Feb, Mar all count in full).
	 *
	 * @return void
	 */
	public function testMidMonthStartCountsWholeMonth(): void {
		$orders = [
			['orderId' => 'O', 'administrationId' => 'adm-1', 'currency' => 'EUR'],
		];
		$lines = [
			[
				'lineId' => 'M',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'MONTHLY',
				'amount' => 1000.0,
				'termStart' => '2026-01-15',
				'termEnd' => '2026-12-31',
			],
		];

		$result = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(3000.0, $result['recognized']);

		// A March-only term start yields just March.
		$linesMar = [
			[
				'lineId' => 'M',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'MONTHLY',
				'amount' => 1000.0,
				'termStart' => '2026-03-20',
				'termEnd' => '2026-12-31',
			],
		];
		$resultMar = $this->buildService($orders, $linesMar)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');
		self::assertSame(1000.0, $resultMar['recognized']);

	}//end testMidMonthStartCountsWholeMonth()

	/**
	 * A one-off POINT_IN_TIME fee is recognized in full when in-period, 0 when out, never recurring.
	 *
	 * @return void
	 */
	public function testOneOffPointInTimeInAndOutOfPeriod(): void {
		$orders = [
			[
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'currency' => 'EUR',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
			],
		];
		$lines = [
			[
				'lineId' => 'B',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'ONE_OFF',
				'recognitionMethod' => 'POINT_IN_TIME',
				'amount' => 5000.0,
				'recognitionDate' => '2026-01-15',
			],
			[
				'lineId' => 'C',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'MONTHLY',
				'amount' => 1500.0,
			],
		];

		$inPeriod = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-01-31');
		self::assertSame(1500.0, $inPeriod['recognized']);
		self::assertSame(5000.0, $inPeriod['oneOff']);

		$outOfPeriod = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-02-01', '2026-02-28');
		self::assertSame(0.0, $outOfPeriod['oneOff']);
		self::assertSame(1500.0, $outOfPeriod['recognized']);

	}//end testOneOffPointInTimeInAndOutOfPeriod()

	/**
	 * No lines yields a clean zero, not an exception.
	 *
	 * @return void
	 */
	public function testEmptyYieldsZero(): void {
		$result = $this->buildService([], [])->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(0.0, $result['recognized']);
		self::assertSame(0.0, $result['oneOff']);
		self::assertSame(0.0, $result['arr']);
		self::assertSame(0, $result['lineCount']);

	}//end testEmptyYieldsZero()

	/**
	 * A line whose term does not overlap the period contributes 0.
	 *
	 * @return void
	 */
	public function testNonOverlappingTermContributesZero(): void {
		$orders = [['orderId' => 'O', 'administrationId' => 'adm-1', 'currency' => 'EUR']];
		$lines = [
			[
				'lineId' => 'L',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'MONTHLY',
				'amount' => 1000.0,
				'termStart' => '2026-06-01',
				'termEnd' => '2026-12-31',
			],
		];

		$result = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(0.0, $result['recognized']);

	}//end testNonOverlappingTermContributesZero()

	/**
	 * KWARTAALS and WEKELIJKS frequencies normalize to a monthly rate.
	 *
	 * KWARTAALS 3000 → 1000/mo; WEKELIJKS 100 → 433.33/mo (52/12). Over 3 months:
	 * 3000 + 1300 (433.33 × 3, rounded once at boundary).
	 *
	 * @return void
	 */
	public function testFrequencyNormalization(): void {
		$orders = [
			[
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'currency' => 'EUR',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
			],
		];

		$kwartaals = [
			[
				'lineId' => 'K',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'QUARTERLY',
				'amount' => 3000.0,
			],
		];
		$resultK = $this->buildService($orders, $kwartaals)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');
		self::assertSame(3000.0, $resultK['recognized']);
		self::assertSame(12000.0, $resultK['arr']);

		$wekelijks = [
			[
				'lineId' => 'W',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => 'WEEKLY',
				'amount' => 100.0,
			],
		];
		$resultW = $this->buildService($orders, $wekelijks)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');
		// Monthly rate = round(100 × 52/12) = 433.33 (cents-rounded) → ×3 months = 1299.99.
		self::assertSame(1299.99, $resultW['recognized']);

	}//end testFrequencyNormalization()

	/**
	 * A RECURRING line with a null/unknown frequentie contributes 0 and is logged (fail-closed).
	 *
	 * @return void
	 */
	public function testNullFrequentieRecurringContributesZeroAndLogs(): void {
		$orders = [
			[
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'currency' => 'EUR',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
			],
		];
		$lines = [
			[
				'lineId' => 'X',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'RECURRING',
				'frequency' => null,
				'amount' => 9999.0,
			],
		];

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(0.0, $result['recognized']);

	}//end testNullFrequentieRecurringContributesZeroAndLogs()

	/**
	 * A one-off OVER_TIME line is prorated across its own term, separate from recurring.
	 *
	 * An amount of 6000 over a 12-month term [2026-01-01, 2026-12-31], period
	 * [2026-01-01, 2026-03-31] (3 months) → 6000 × 3/12 = 1500.
	 *
	 * @return void
	 */
	public function testOneOffOverTimeProratedAcrossTerm(): void {
		$orders = [
			[
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'currency' => 'EUR',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
			],
		];
		$lines = [
			[
				'lineId' => 'OT',
				'orderId' => 'O',
				'administrationId' => 'adm-1',
				'nature' => 'ONE_OFF',
				'recognitionMethod' => 'OVER_TIME',
				'amount' => 6000.0,
			],
		];

		$result = $this->buildService($orders, $lines)->computeRecurring('adm-1', '2026-01-01', '2026-03-31');

		self::assertSame(1500.0, $result['oneOff']);
		self::assertSame(0.0, $result['recognized']);

	}//end testOneOffOverTimeProratedAcrossTerm()
}//end class
