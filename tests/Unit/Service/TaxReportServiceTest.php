<?php

/**
 * Unit tests for TaxReportService.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxReportCalculator;
use OCA\Shillinq\Service\TaxReportService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the Vpb quarterly/annual statement computation against a stubbed ObjectService.
 *
 * Covers REQ-VPB-003 (period + administration scoping), REQ-VPB-009 (aggregation),
 * REQ-VPB-010 (untagged warning) and REQ-VPB-012 (annual roll-up).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxReportServiceTest extends TestCase {

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
	 * The service under test.
	 *
	 * @var TaxReportService
	 */
	private TaxReportService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->service = $this->buildService($this->buildObjectServiceStub([]));

	}//end setUp()

	/**
	 * Build the subject around a seeded in-memory ObjectService store.
	 *
	 * The store used to reach the subject through the container; ADR-084 injects
	 * it as a contract-typed constructor argument instead, so each test rebuilds
	 * the subject with its own store.
	 *
	 * @param object $store The seeded in-memory ObjectService double.
	 *
	 * @return TaxReportService
	 */
	private function buildService(object $store): TaxReportService {
		return new TaxReportService(
			appConfig: $this->appConfig,
			calculator: new TaxReportCalculator(),
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildService()

	/**
	 * Quarter computation joins GLLine to Account and aggregates by tax treatment (REQ-VPB-003, REQ-VPB-009).
	 *
	 * @return void
	 */
	public function testComputeQuarterAggregatesScopedRows(): void {
		$records = [
			'GLTransaction' => [
				['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2025-Q1'],
			],
			'Account' => [
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
				['accountNumber' => '4000', 'name' => 'Inkoop', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
			],
			'GLLine' => [
				$this->glLine('txn-1', '8000', 120000.0, 'credit', 'normal', '2025-Q1'),
				$this->glLine('txn-1', '4000', 80000.0, 'debit', 'normal', '2025-Q1'),
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->computeQuarter(administrationId: 'adm-1', fiscalYear: 2025, quarter: 1);

		self::assertSame('2025-Q1', $result['periodId']);
		self::assertSame(120000.0, $result['revenue']);
		self::assertSame(80000.0, $result['operatingExpenses']);
		self::assertSame(40000.0, $result['netTaxableIncome']);
		self::assertSame(0, $result['untaggedCount']);
		self::assertCount(2, $result['breakdown']);

	}//end testComputeQuarterAggregatesScopedRows()

	/**
	 * Lines whose parent transaction is out of scope are excluded (REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testOutOfScopeLinesExcluded(): void {
		$records = [
			'GLTransaction' => [
				['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2025-Q1'],
			],
			'Account' => [
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
			],
			'GLLine' => [
				$this->glLine('txn-1', '8000', 1000.0, 'credit', 'normal', '2025-Q1'),
				// Belongs to a transaction not in scope — must be ignored.
				$this->glLine('txn-other', '8000', 9999.0, 'credit', 'normal', '2025-Q1'),
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->computeQuarter(administrationId: 'adm-1', fiscalYear: 2025, quarter: 1);

		self::assertSame(1000.0, $result['revenue']);

	}//end testOutOfScopeLinesExcluded()

	/**
	 * Untagged tax-relevant postings raise the warning count (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testUntaggedPostingsReported(): void {
		$records = [
			'GLTransaction' => [['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2025-Q2']],
			'Account' => [['accountNumber' => '4000', 'name' => 'Inkoop', 'accountType' => 'expenses', 'administrationId' => 'adm-1']],
			'GLLine' => [
				$this->glLine('txn-1', '4000', 500.0, 'debit', '', '2025-Q2'),
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->computeQuarter(administrationId: 'adm-1', fiscalYear: 2025, quarter: 2);

		self::assertSame(1, $result['untaggedCount']);

	}//end testUntaggedPostingsReported()

	/**
	 * Annual computation rolls up four quarters and estimates the liability (REQ-VPB-012).
	 *
	 * @return void
	 */
	public function testComputeAnnualRollsUpQuarters(): void {
		// Every quarter returns the same single revenue line via the stub.
		$records = [
			'GLTransaction' => [['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => 'any']],
			'Account' => [['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-1']],
			'GLLine' => [
				$this->glLine('txn-1', '8000', 50000.0, 'credit', 'normal', 'any'),
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records, ignorePeriod: true));

		$result = $this->service->computeAnnual(administrationId: 'adm-1', fiscalYear: 2025);

		self::assertCount(4, $result['quarters']);
		// 4 quarters x 50000 = 200000 revenue.
		self::assertSame(200000.0, $result['revenue']);
		self::assertSame(200000.0, $result['netTaxableIncome']);
		// 19% of 200000.
		self::assertSame(38000.0, $result['estimatedLiability']);

	}//end testComputeAnnualRollsUpQuarters()

	/**
	 * Build a GLLine record array for the stub.
	 *
	 * @param string $txn Transaction id.
	 * @param string $account Account number.
	 * @param float $amount Posting amount.
	 * @param string $side 'debit' or 'credit'.
	 * @param string $treatment Tax treatment tag.
	 * @param string $period Fiscal period id.
	 *
	 * @return array<string,mixed>
	 */
	private function glLine(string $txn, string $account, float $amount, string $side, string $treatment, string $period): array {
		return [
			'transactionId' => $txn,
			'accountNumber' => $account,
			'amount' => $amount,
			'side' => $side,
			'taxTreatment' => $treatment,
			'periodId' => $period,
		];

	}//end glLine()

	/**
	 * Build a fluent ObjectService stub returning records by schema.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Map of schema → records.
	 * @param bool $ignorePeriod When true, GLTransaction matches any period
	 *                           (so the annual roll-up sees the same data each quarter).
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema, bool $ignorePeriod = false): object {
		return new class($recordsBySchema, $ignorePeriod) {
			/**
			 * Records keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Whether to ignore the period filter on GLTransaction.
			 *
			 * @var boolean
			 */
			private bool $ignorePeriod;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Records by schema.
			 * @param bool $ignorePeriod Ignore the period filter.
			 */
			public function __construct(array $recordsBySchema, bool $ignorePeriod) {
				$this->recordsBySchema = $recordsBySchema;
				$this->ignorePeriod = $ignorePeriod;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records for the current schema, honouring a periodId filter.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->recordsBySchema[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);
				$period = ($filters['periodId'] ?? null);
				if ($period === null || $this->ignorePeriod === true) {
					return $records;
				}

				return array_values(
					array_filter(
						$records,
						static function (array $r) use ($period): bool {
							return ($r['periodId'] ?? $period) === $period;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
