<?php

/**
 * Unit tests for FinancialDashboardService.
 *
 * Covers the Wave-4 financial endpoints' data layer: the series and summary
 * payload shapes, the one-fetch-per-schema guarantee, the UNLIMITED
 * ObjectService query (regression guard for the client's 2000-object
 * truncation), the per-schema failure resilience (one broken schema cannot
 * blank the dashboard) and the previous-period window arithmetic (same
 * length immediately before the current window).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the financial-series / financial-summary endpoint assembly.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FinancialDashboardServiceTest extends TestCase {

	/**
	 * Chart-of-accounts fixture (identical to the vitest ACCOUNTS fixture).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const ACCOUNTS = [
		['accountNumber' => '8000', 'name' => 'Omzet consultancy', 'accountType' => 'revenue'],
		['accountNumber' => '4000', 'name' => 'Personeelskosten', 'accountType' => 'expenses'],
		['accountNumber' => '1010', 'name' => 'Zakelijke rekening', 'accountType' => 'assets'],
		['accountNumber' => '1300', 'name' => 'Debiteuren', 'accountType' => 'assets'],
	];

	/**
	 * GLTransaction fixture: a January posting (previous window), the March /
	 * April postings from the vitest fixture, and a draft that must be
	 * ignored.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const TRANSACTIONS = [
		['id' => 'tx-0', 'transactionNumber' => 'TX-0', 'postingDate' => '2026-01-10 00:00:00', 'state' => 'posted'],
		['id' => 'tx-1', 'transactionNumber' => 'TX-1', 'postingDate' => '2026-03-05 00:00:00', 'state' => 'posted'],
		['id' => 'tx-2', 'transactionNumber' => 'TX-2', 'postingDate' => '2026-03-20 00:00:00', 'state' => 'posted'],
		['id' => 'tx-3', 'transactionNumber' => 'TX-3', 'postingDate' => '2026-04-02 00:00:00', 'state' => 'posted'],
		['id' => 'tx-draft', 'transactionNumber' => 'TX-D', 'postingDate' => '2026-03-09 00:00:00', 'state' => 'draft'],
	];

	/**
	 * GLLine fixture: January revenue 500, March revenue 1000 + costs 400
	 * (cash out), April customer payment 1000 (cash in), plus ignorable
	 * draft-parent and orphan lines.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const LINES = [
		['transactionId' => 'tx-0', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 500],
		['transactionId' => 'tx-0', 'accountNumber' => '1300', 'side' => 'debit', 'amount' => 500],
		['transactionId' => 'tx-1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 1000],
		['transactionId' => 'tx-1', 'accountNumber' => '1300', 'side' => 'debit', 'amount' => 1000],
		['transactionId' => 'tx-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 400],
		['transactionId' => 'tx-2', 'accountNumber' => '1010', 'side' => 'credit', 'amount' => 400],
		['transactionId' => 'TX-3', 'accountNumber' => '1010', 'side' => 'debit', 'amount' => 1000],
		['transactionId' => 'TX-3', 'accountNumber' => '1300', 'side' => 'credit', 'amount' => 1000],
		['transactionId' => 'tx-draft', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 9999],
		['transactionId' => 'tx-missing', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 5555],
	];

	/**
	 * UrenRegistratie fixture: April 30h billable / 10h non-billable.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const HOUR_ENTRIES = [
		['date' => '2026-04-02', 'hours' => 30, 'recognisedRate' => 95],
		['date' => '2026-04-03', 'hours' => 10],
	];

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
	 * Set up shared mocks.
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
	 * Build the service wired to an ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return FinancialDashboardService
	 */
	private function buildService(object $objectService): FinancialDashboardService {
		$this->container->method('get')->willReturn($objectService);

		return new FinancialDashboardService(
			container: $this->container,
			appConfig: $this->appConfig,
			calculator: new FinancialSeriesCalculator(),
			logger: $this->logger,
		);

	}//end buildService()

	/**
	 * Build a recording ObjectService stub that returns per-schema records
	 * based on setSchema() and records every findAll() invocation.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Schema slug => records to return from findAll().
	 * @param array<int,string> $failingSchemas Schema slugs whose findAll() throws.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema, array $failingSchemas = []): object {
		return new class($recordsBySchema, $failingSchemas) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * Schema slugs whose findAll() throws.
			 *
			 * @var array<int,string>
			 */
			private array $failingSchemas;

			/**
			 * Recorded findAll() calls: list of [schema, params].
			 *
			 * @var array<int,array{schema: string, params: array<string,mixed>}>
			 */
			public array $calls = [];

			/**
			 * Currently selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
			 * @param array<int,string> $failingSchemas Schemas whose findAll() throws.
			 */
			public function __construct(array $recordsBySchema, array $failingSchemas) {
				$this->recordsBySchema = $recordsBySchema;
				$this->failingSchemas = $failingSchemas;
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
			 * Fluent schema setter — records the active schema.
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
			 * Return the records for the active schema, recording the call.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException When the schema is marked as failing.
			 */
			public function findAll(array $params = []): array {
				$this->calls[] = [
					'schema' => $this->schema,
					'params' => $params,
				];
				if (in_array($this->schema, $this->failingSchemas, true) === true) {
					throw new \RuntimeException('boom');
				}

				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * The full fixture data set keyed by schema slug.
	 *
	 * @return array<string,array<mixed>>
	 */
	private function fixtureRecords(): array {
		return [
			'Account' => self::ACCOUNTS,
			'GLTransaction' => self::TRANSACTIONS,
			'GLLine' => self::LINES,
			'UrenRegistratie' => self::HOUR_ENTRIES,
			'ARInvoice' => [
				['id' => 'a', 'dueDate' => '2026-05-01', 'grossAmount' => 1210, 'lifecycleState' => 'issued'],
			],
			'APTransaction' => [
				['id' => 'b', 'dueDate' => '2026-05-01', 'totalAmount' => 484, 'state' => 'received'],
			],
		];
	}//end fixtureRecords()

	/**
	 * series() returns the ten parallel arrays over the explicit range.
	 *
	 * @return void
	 */
	public function testSeriesReturnsMonthlySeriesOverExplicitRange(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords());
		$service = $this->buildService($stub);

		$result = $service->series('2026-02-01', '2026-04-30');

		$this->assertSame(['2026-02', '2026-03', '2026-04'], $result['months']);
		$this->assertSame([0.0, 1000.0, 0.0], $result['revenue']);
		$this->assertSame([0.0, 400.0, 0.0], $result['costs']);
		$this->assertSame([0.0, 600.0, 0.0], $result['margin']);
		$this->assertSame([null, 60.0, null], $result['marginPct']);
		$this->assertSame([0.0, 0.0, 30.0], $result['billableHours']);
		$this->assertSame([0.0, 0.0, 10.0], $result['nonBillableHours']);
		$this->assertSame([null, null, 75.0], $result['billablePct']);
		$this->assertSame([0.0, 0.0, 1000.0], $result['cashIn']);
		$this->assertSame([0.0, 400.0, 0.0], $result['cashOut']);
		$this->assertSame([0.0, -400.0, 1000.0], $result['cashNet']);

	}//end testSeriesReturnsMonthlySeriesOverExplicitRange()

	/**
	 * series() fetches each schema exactly once and NEVER passes a limit —
	 * the regression guard for the client's 2000-object `_limit` truncation.
	 *
	 * @return void
	 */
	public function testSeriesFetchesEachSchemaOnceWithoutAnyLimit(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords());
		$service = $this->buildService($stub);

		$service->series('2026-02-01', '2026-04-30');

		$schemas = array_column($stub->calls, 'schema');
		sort($schemas);
		$this->assertSame(['Account', 'GLLine', 'GLTransaction', 'UrenRegistratie'], $schemas);

		foreach ($stub->calls as $call) {
			$this->assertArrayNotHasKey('limit', $call['params']);
			$this->assertArrayNotHasKey('_limit', $call['params']);
		}

	}//end testSeriesFetchesEachSchemaOnceWithoutAnyLimit()

	/**
	 * series() falls back to the trailing 12 months when no range is given.
	 *
	 * @return void
	 */
	public function testSeriesFallsBackToTrailingTwelveMonthsWithoutRange(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords());
		$service = $this->buildService($stub);

		$result = $service->series(null, null, new DateTimeImmutable('2026-04-15'));

		$this->assertCount(12, $result['months']);
		$this->assertSame('2025-05', $result['months'][0]);
		$this->assertSame('2026-04', $result['months'][11]);

	}//end testSeriesFallsBackToTrailingTwelveMonthsWithoutRange()

	/**
	 * A failing schema resolves to an empty list (and an error log) instead
	 * of blanking the whole payload — per-schema resilience like the client.
	 *
	 * @return void
	 */
	public function testSeriesToleratesOneFailingSchema(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords(), ['GLLine']);
		$service = $this->buildService($stub);

		$this->logger->expects($this->once())->method('error');

		$result = $service->series('2026-02-01', '2026-04-30');

		// No GL lines means flat-zero money series, but hours still flow.
		$this->assertSame([0.0, 0.0, 0.0], $result['revenue']);
		$this->assertSame([0.0, 0.0, 30.0], $result['billableHours']);

	}//end testSeriesToleratesOneFailingSchema()

	/**
	 * summary() returns current + previousPeriod where the previous window
	 * has the same length immediately before the current one, and the
	 * point-in-time metrics (open AR/AP, cash position) sit under current.
	 *
	 * @return void
	 */
	public function testSummaryComputesCurrentAndPreviousPeriodWindows(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords());
		$service = $this->buildService($stub);

		$result = $service->summary('2026-03-01', '2026-04-30', new DateTimeImmutable('2026-04-15'));

		$this->assertSame(['2026-03', '2026-04'], $result['months']);
		$this->assertSame(['2026-01', '2026-02'], $result['previousMonths']);

		// Current window: March revenue 1000, costs 400.
		$this->assertSame(1000.0, $result['current']['turnover']);
		$this->assertSame(600.0, $result['current']['margin']);
		$this->assertSame(60.0, $result['current']['marginPct']);
		$this->assertSame(30.0, $result['current']['billableHours']);
		$this->assertSame(75.0, $result['current']['billablePct']);

		// Point-in-time metrics do not vary by range and live under current.
		$this->assertSame(['count' => 1, 'amount' => 1210.0], $result['current']['openDebtors']);
		$this->assertSame(['count' => 1, 'amount' => 484.0], $result['current']['openCreditors']);
		$this->assertSame(600.0, $result['current']['cashPosition']);

		// Previous window (Jan+Feb): January revenue 500, no costs, no hours.
		$this->assertSame(500.0, $result['previousPeriod']['turnover']);
		$this->assertSame(500.0, $result['previousPeriod']['margin']);
		$this->assertSame(100.0, $result['previousPeriod']['marginPct']);
		$this->assertSame(0.0, $result['previousPeriod']['billableHours']);
		$this->assertNull($result['previousPeriod']['billablePct']);

	}//end testSummaryComputesCurrentAndPreviousPeriodWindows()

	/**
	 * summary() fetches the six summary schemas exactly once each — the data
	 * set is shared between the current window, the previous window and the
	 * point-in-time metrics.
	 *
	 * @return void
	 */
	public function testSummaryFetchesEachSchemaExactlyOnce(): void {
		$stub = $this->buildObjectServiceStub($this->fixtureRecords());
		$service = $this->buildService($stub);

		$service->summary('2026-03-01', '2026-04-30', new DateTimeImmutable('2026-04-15'));

		$schemas = array_column($stub->calls, 'schema');
		sort($schemas);
		$this->assertSame(
			['APTransaction', 'ARInvoice', 'Account', 'GLLine', 'GLTransaction', 'UrenRegistratie'],
			$schemas
		);

	}//end testSummaryFetchesEachSchemaExactlyOnce()

	/**
	 * When OpenRegister is unavailable entirely, both endpoints degrade to
	 * empty data sets instead of throwing.
	 *
	 * @return void
	 */
	public function testSummaryDegradesGracefullyWithoutOpenRegister(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR not installed'));

		$service = new FinancialDashboardService(
			container: $this->container,
			appConfig: $this->appConfig,
			calculator: new FinancialSeriesCalculator(),
			logger: $this->logger,
		);

		$result = $service->summary('2026-03-01', '2026-04-30', new DateTimeImmutable('2026-04-15'));

		$this->assertSame(0.0, $result['current']['turnover']);
		$this->assertSame(['count' => 0, 'amount' => 0.0], $result['current']['openDebtors']);
		$this->assertSame(0.0, $result['current']['cashPosition']);

	}//end testSummaryDegradesGracefullyWithoutOpenRegister()
}//end class
