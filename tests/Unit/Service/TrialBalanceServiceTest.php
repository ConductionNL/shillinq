<?php

/**
 * Unit tests for TrialBalanceService.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-3
 * KNOWINGLY DANGLING until shillinq#500 — see TrialBalanceService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TrialBalanceCalculator;
use OCA\Shillinq\Service\TrialBalanceService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the on-demand trial-balance computation service.
 *
 * Covers REQ-TB-008 (compute), REQ-TB-002 (opening from prior period),
 * REQ-TB-003 (closing formula), REQ-TB-006 (account metadata inherited),
 * and REQ-TB-017 (administration + period scoping).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TrialBalanceServiceTest extends TestCase {

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
	 * @param array<int,array<string,mixed>> $accounts Account records.
	 * @param array<int,array<string,mixed>> $transactions GLTransaction records.
	 * @param array<int,array<string,mixed>> $lines GLLine records.
	 *
	 * @return TrialBalanceService
	 */
	private function buildService(array $accounts, array $transactions, array $lines): TrialBalanceService {
		$stub = new class($accounts, $transactions, $lines) {

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
			 * @param array<int,array<string,mixed>> $accounts Account records.
			 * @param array<int,array<string,mixed>> $transactions GLTransaction records.
			 * @param array<int,array<string,mixed>> $lines GLLine records.
			 */
			public function __construct(array $accounts, array $transactions, array $lines) {
				$this->data = [
					'Account' => $accounts,
					'GLTransaction' => $transactions,
					'GLLine' => $lines,
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

		$this->container->method('get')->willReturn($stub);

		return new TrialBalanceService(
			appConfig: $this->appConfig,
			calculator: new TrialBalanceCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build an Account fixture row.
	 *
	 * @param string $number The account number.
	 * @param string $name The account name.
	 * @param string $type The account type.
	 * @param string $admin The administration id.
	 * @param string|null $parent The parent account number.
	 *
	 * @return array<string,mixed>
	 */
	private function account(string $number, string $name, string $type, string $admin, ?string $parent = null): array {
		return [
			'accountNumber' => $number,
			'name' => $name,
			'accountType' => $type,
			'currency' => 'EUR',
			'parentAccountNumber' => $parent,
			'administrationId' => $admin,
		];

	}//end account()

	/**
	 * Build a GLLine fixture row.
	 *
	 * @param string $txn The transaction id.
	 * @param string $account The account number.
	 * @param string $side Debit or credit.
	 * @param float $amount The amount.
	 * @param string $period The period id.
	 *
	 * @return array<string,mixed>
	 */
	private function line(string $txn, string $account, string $side, float $amount, string $period): array {
		return [
			'transactionId' => $txn,
			'accountNumber' => $account,
			'side' => $side,
			'amount' => $amount,
			'periodId' => $period,
		];

	}//end line()

	/**
	 * The compute() method sums period movements and inherits account metadata (REQ-TB-006, REQ-TB-008).
	 *
	 * @return void
	 */
	public function testComputeSumsMovementsAndInheritsAccountMetadata(): void {
		$accounts = [$this->account('1000', 'Activa', 'assets', 'adm-1')];
		$transactions = [
			['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1'],
		];
		$lines = [
			$this->line('txn-1', '1000', 'debit', 10000.0, '2026-Q1'),
			$this->line('txn-1', '1000', 'credit', 5000.0, '2026-Q1'),
		];

		$service = $this->buildService($accounts, $transactions, $lines);
		$result = $service->compute('adm-1', '2026-Q1');

		self::assertSame(1, $result['total']);
		$row = $result['data'][0];
		self::assertSame('1000', $row['accountNumber']);
		self::assertSame('Activa', $row['accountName']);
		self::assertSame('assets', $row['accountType']);
		self::assertSame(10000.0, $row['debitMovement']);
		self::assertSame(5000.0, $row['creditMovement']);
		// Opening 0 (no prior period) + (10000 - 5000) = 5000.
		self::assertSame(5000.0, $row['closingBalance']);

	}//end testComputeSumsMovementsAndInheritsAccountMetadata()

	/**
	 * The compute() method carries the opening balance from the prior period (REQ-TB-002, REQ-TB-003).
	 *
	 * @return void
	 */
	public function testComputeCarriesOpeningFromPriorPeriod(): void {
		$accounts = [$this->account('1000', 'Activa', 'assets', 'adm-1')];
		$transactions = [
			['id' => 'txn-prior', 'administrationId' => 'adm-1', 'periodId' => '2025-Q4'],
			['id' => 'txn-now', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1'],
		];
		$lines = [
			// Prior period: net debit 50000 → prior closing 50000 → this opening 50000.
			$this->line('txn-prior', '1000', 'debit', 50000.0, '2025-Q4'),
			// Current period movements.
			$this->line('txn-now', '1000', 'debit', 10000.0, '2026-Q1'),
			$this->line('txn-now', '1000', 'credit', 5000.0, '2026-Q1'),
		];

		$service = $this->buildService($accounts, $transactions, $lines);
		$result = $service->compute('adm-1', '2026-Q1', ['priorPeriodId' => '2025-Q4']);

		$row = $result['data'][0];
		self::assertSame(50000.0, $row['openingBalance']);
		// 50000 + (10000 - 5000) = 55000.
		self::assertSame(55000.0, $row['closingBalance']);

	}//end testComputeCarriesOpeningFromPriorPeriod()

	/**
	 * The compute() method excludes movements from another administration (REQ-TB-017).
	 *
	 * @return void
	 */
	public function testComputeScopesToAdministration(): void {
		$accounts = [$this->account('1000', 'Bank', 'assets', 'adm-1')];
		$transactions = [
			['id' => 'txn-mine', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1'],
			['id' => 'txn-theirs', 'administrationId' => 'adm-2', 'periodId' => '2026-Q1'],
		];
		$lines = [
			$this->line('txn-mine', '1000', 'debit', 2000.0, '2026-Q1'),
			// Belongs to adm-2; must NOT count toward adm-1's trial balance.
			$this->line('txn-theirs', '1000', 'debit', 9999.0, '2026-Q1'),
		];

		$service = $this->buildService($accounts, $transactions, $lines);
		$result = $service->compute('adm-1', '2026-Q1');

		$row = $result['data'][0];
		self::assertSame(2000.0, $row['debitMovement']);

	}//end testComputeScopesToAdministration()

	/**
	 * The compute() method reports the balanced flag and account-type totals (REQ-TB-011).
	 *
	 * @return void
	 */
	public function testComputeReportsTotalsAndBalancedFlag(): void {
		$accounts = [
			$this->account('1000', 'Activa', 'assets', 'adm-1'),
			$this->account('2000', 'Schulden', 'liabilities', 'adm-1'),
		];
		$transactions = [
			['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1'],
		];
		$lines = [
			$this->line('txn-1', '1000', 'debit', 7000.0, '2026-Q1'),
			$this->line('txn-1', '2000', 'credit', 7000.0, '2026-Q1'),
		];

		$service = $this->buildService($accounts, $transactions, $lines);
		$result = $service->compute('adm-1', '2026-Q1');

		self::assertTrue($result['isBalanced']);
		self::assertSame(7000.0, $result['totals']['totalDebit']);
		self::assertSame(7000.0, $result['totals']['totalCredit']);

	}//end testComputeReportsTotalsAndBalancedFlag()
}//end class
