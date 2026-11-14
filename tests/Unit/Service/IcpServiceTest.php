<?php

/**
 * Unit tests for IcpService.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the on-demand ICP ledger / reconciliation / periodicity service.
 *
 * Covers REQ-ICP-003 (period-window selection + aggregation), REQ-ICP-002
 * (periodicity threshold per quarter), REQ-ICP-004 (reconciliation against the
 * BTW-aangifte rubriek 3b incl. missing) and REQ-ICP-001 (administration scoping).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpServiceTest extends TestCase {

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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service with an ObjectService stub returning the given data sets.
	 *
	 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
	 * @param array<int,array<string,mixed>> $returns VatReturn records.
	 * @param array<int,array<string,mixed>> $validations ViesValidation records.
	 * @param array<int,array<string,mixed>> $opgaven IcpOpgaaf records.
	 *
	 * @return IcpService
	 */
	private function buildService(array $supplies, array $returns, array $validations = [], array $opgaven = []): IcpService {
		$stub = new class($supplies, $returns, $validations, $opgaven) {

			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Last schema selected.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
			 * @param array<int,array<string,mixed>> $returns VatReturn records.
			 * @param array<int,array<string,mixed>> $validations ViesValidation records.
			 * @param array<int,array<string,mixed>> $opgaven IcpOpgaaf records.
			 */
			public function __construct(array $supplies, array $returns, array $validations, array $opgaven) {
				$this->data = [
					'IcpSupply' => $supplies,
					'VatReturn' => $returns,
					'ViesValidation' => $validations,
					'IcpOpgaaf' => $opgaven,
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
			 * Captured saved objects keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Capture a saved object (mirrors the real ObjectService::saveObject).
			 *
			 * @param array<string,mixed> $object The object to save.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[$schema][] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Return the data set for the active schema, applying equality filters.
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

		$this->container->method('get')->willReturn($stub);

		return new IcpService(
			appConfig: $this->appConfig,
			calculator: new IcpCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build a compact IcpSupply test row.
	 *
	 * @param string $adm Administration id.
	 * @param string $date Supply date (YYYY-MM-DD).
	 * @param string $buyer Buyer VAT-ID.
	 * @param string $type Supply type (L/S/T).
	 * @param float $amount Amount excl. VAT.
	 *
	 * @return array<string,mixed>
	 */
	private function supply(string $adm, string $date, string $buyer, string $type, float $amount): array {
		return [
			'administrationId' => $adm,
			'supplyDate' => $date,
			'buyerVatId' => $buyer,
			'supplyType' => $type,
			'amountExclVat' => $amount,
		];

	}//end supply()

	/**
	 * Build a compact VatReturn test row carrying a rubriek 3b value.
	 *
	 * @param string $adm Administration id.
	 * @param string $year Period year.
	 * @param string $quarter Period quarter (1-4).
	 * @param float $section The rubriek 3b value.
	 *
	 * @return array<string,mixed>
	 */
	private function vatReturn(string $adm, string $year, string $quarter, float $section): array {
		return [
			'administrationId' => $adm,
			'periodType' => 'quarter',
			'periodYear' => $year,
			'periodQuarter' => $quarter,
			'rubriek3b' => $section,
		];

	}//end vatReturn()

	/**
	 * The ledger selects only in-period supplies and aggregates them (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testLedgerSelectsInPeriodAndAggregates(): void {
		$supplies = [
			// In Q2.
			$this->supply('adm-1', '2026-06-15', 'BE0123456789', 'L', 25000.0),
			$this->supply('adm-1', '2026-04-01', 'DE0987654321', 'S', 12500.0),
			// In Q1 — must be excluded from Q2.
			$this->supply('adm-1', '2026-02-10', 'BE0123456789', 'L', 99999.0),
			// Another administration — must be excluded.
			$this->supply('adm-2', '2026-05-01', 'BE0123456789', 'L', 7777.0),
		];

		$service = $this->buildService($supplies, []);
		$ledger = $service->ledger(administrationId: 'adm-1', period: '2026-Q2');

		self::assertSame(2, $ledger['supplyCount']);
		self::assertCount(2, $ledger['lines']);
		self::assertSame(37500.0, $ledger['total']);
		self::assertSame(25000.0, $ledger['totalGoods']);
		self::assertSame(12500.0, $ledger['totalServices']);

	}//end testLedgerSelectsInPeriodAndAggregates()

	/**
	 * A monthly period selects only that month's supplies (REQ-ICP-002, REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testLedgerMonthlyPeriod(): void {
		$supplies = [
			$this->supply('adm-1', '2026-04-10', 'BE0123456789', 'L', 1000.0),
			$this->supply('adm-1', '2026-05-10', 'BE0123456789', 'L', 2000.0),
		];

		$service = $this->buildService($supplies, []);
		$ledger = $service->ledger(administrationId: 'adm-1', period: '2026-04');

		self::assertSame(1, $ledger['supplyCount']);
		self::assertSame(1000.0, $ledger['total']);

	}//end testLedgerMonthlyPeriod()

	/**
	 * The periodicity check sums quarter goods and detects the breach (REQ-ICP-002).
	 *
	 * @return void
	 */
	public function testPeriodicityCheckDetectsBreach(): void {
		$supplies = [
			$this->supply('adm-1', '2026-01-15', 'BE0123456789', 'L', 49800.0),
			$this->supply('adm-1', '2026-03-28', 'BE0123456789', 'L', 300.0),
		];

		$service = $this->buildService($supplies, []);
		$decision = $service->periodicityCheck(administrationId: 'adm-1', quarter: '2026-Q1');

		self::assertTrue($decision['breached']);
		self::assertSame(50100.0, $decision['goodsCumulative']);

	}//end testPeriodicityCheckDetectsBreach()

	/**
	 * Reconciliation matches against a VatReturn's rubriek 3b proxy (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconcileMatches(): void {
		$supplies = [$this->supply('adm-1', '2026-06-15', 'BE0123456789', 'L', 25000.0)];
		$returns = [$this->vatReturn('adm-1', '2026', '2', 25000.0)];

		$service = $this->buildService($supplies, $returns);
		$outcome = $service->reconcile(administrationId: 'adm-1', period: '2026-Q2');

		self::assertTrue($outcome['matches']);
		self::assertFalse($outcome['missing']);
		self::assertSame(25000.0, $outcome['rubriek3b']);

	}//end testReconcileMatches()

	/**
	 * Reconciliation reports a missing BTW-aangifte when none exists (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconcileMissingBtw(): void {
		$supplies = [$this->supply('adm-1', '2026-06-15', 'BE0123456789', 'L', 25000.0)];

		$service = $this->buildService($supplies, []);
		$outcome = $service->reconcile(administrationId: 'adm-1', period: '2026-Q2');

		self::assertFalse($outcome['matches']);
		self::assertTrue($outcome['missing']);
		self::assertNull($outcome['rubriek3b']);

	}//end testReconcileMissingBtw()

	/**
	 * Reconciliation detects a divergence beyond the EUR 1 tolerance (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconcileMismatch(): void {
		$supplies = [$this->supply('adm-1', '2026-06-15', 'BE0123456789', 'L', 25000.0)];
		$returns = [$this->vatReturn('adm-1', '2026', '2', 24000.0)];

		$service = $this->buildService($supplies, $returns);
		$outcome = $service->reconcile(administrationId: 'adm-1', period: '2026-Q2');

		self::assertFalse($outcome['matches']);
		self::assertFalse($outcome['missing']);
		self::assertSame(1000.0, $outcome['difference']);

	}//end testReconcileMismatch()

	/**
	 * The suppliesInPeriod accessor exposes the in-period supplies for the audit-export path.
	 *
	 * @return void
	 */
	public function testSuppliesInPeriodFiltersByPeriod(): void {
		$supplies = [
			$this->supply('adm-1', '2026-06-15', 'BE0123456789', 'L', 25000.0),
			$this->supply('adm-1', '2026-09-15', 'BE0123456789', 'L', 5000.0),
		];

		$service = $this->buildService($supplies, []);
		$inQ2 = $service->suppliesInPeriod(administrationId: 'adm-1', period: '2026-Q2');

		self::assertCount(1, $inQ2);
		self::assertSame('2026-06-15', $inQ2[0]['supplyDate']);

	}//end testSuppliesInPeriodFiltersByPeriod()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

	/**
	 * Pin which supplies fall in which period, over every date and period shape.
	 *
	 * @return void
	 */
	public function testSupplyPeriodMembershipOverEveryShape(): void {
		$supplies = [];
		foreach (['2026-01-31', '2026-03-01', '2026-04-15', '2026-12-31', '2025-12-31', '2026-13-01', '2026-00-10', '2026-4-15', '26-04-15', ''] as $i => $date) {
			$supplies[] = ['id' => 's' . $i, 'administrationId' => 'adm', 'supplyDate' => $date];
		}
		$supplies[] = ['id' => 'nodate', 'administrationId' => 'adm'];
		$service = $this->buildService($supplies, []);
		$actual = [];
		foreach (['2026-Q1', '2026-Q2', '2026-Q4', '2026-Q5', '2026-Q0', '2026-01', '2026-04', '2026-13', '2026-00', '2026-4', '2026', 'Q1', ''] as $period) {
			$actual[$period] = array_column($service->suppliesInPeriod('adm', $period), 'id');
		}

		self::assertSame(
			[
				'2026-Q1' => ['s0', 's1'],
				'2026-Q2' => ['s2'],
				'2026-Q4' => ['s3'],
				'2026-Q5' => [],
				'2026-Q0' => [],
				'2026-01' => ['s0'],
				'2026-04' => ['s2'],
				'2026-13' => ['s5'],
				'2026-00' => ['s6'],
				'2026-4' => [],
				'2026' => [],
				'Q1' => [],
				'' => [],
			],
			$actual
		);

	}//end testSupplyPeriodMembershipOverEveryShape()

	/**
	 * Pin the rubriek 3b read over every VatReturn period shape and field.
	 *
	 * @return void
	 */
	public function testRubriek3bOverEveryReturnShape(): void {
		$returns = [
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'quarterly', 'periodQuarter' => 'Q1', 'rubriek3b' => '100.5', 'amount' => 9],
			['administrationId' => 'adm', 'periodYear' => '2026', 'periodType' => 'quarter', 'periodQuarter' => 2, 'rubriek3b' => null, 'amount' => 20],
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'quarter', 'periodQuarter' => '', 'period' => '2026-Q3', 'amount' => null],
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'monthly', 'periodMonth' => 5, 'rubriek3b' => 0],
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'month', 'periodMonth' => '11'],
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'month', 'periodMonth' => '', 'period' => '2026-07', 'amount' => '7'],
			['administrationId' => 'adm', 'periodYear' => 2026, 'periodType' => 'yearly', 'period' => '2026-08', 'rubriek3b' => 8],
			['administrationId' => 'adm', 'period' => '2026-09', 'rubriek3b' => 9],
			['administrationId' => 'adm', 'period' => '2026-09', 'rubriek3b' => 99],
			['administrationId' => 'other', 'period' => '2026-10', 'rubriek3b' => 10],
		];
		$service = $this->buildService([], $returns);
		$actual = [];
		foreach (['2026-Q1', '2026-Q2', '2026-Q3', '2026-05', '2026-11', '2026-07', '2026-08', '2026-09', '2026-10', '2026-12'] as $period) {
			$actual[$period] = $service->reconcile('adm', $period)['rubriek3b'];
		}

		self::assertSame(
			['2026-Q1' => 100.5, '2026-Q2' => 20.0, '2026-Q3' => 0.0, '2026-05' => 0.0, '2026-11' => 0.0, '2026-07' => 7.0, '2026-08' => 8.0, '2026-09' => 9.0, '2026-10' => null, '2026-12' => null],
			$actual
		);

	}//end testRubriek3bOverEveryReturnShape()
}//end class
