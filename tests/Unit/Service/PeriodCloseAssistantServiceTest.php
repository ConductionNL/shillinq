<?php

/**
 * Unit tests for PeriodCloseAssistantService.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the close-assistant detection + flag formatting (REQ-PC-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseAssistantServiceTest extends TestCase {
	/**
	 * Build the service over stubbed data sets keyed by schema slug.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema slug => rows.
	 *
	 * @return PeriodCloseAssistantService
	 */
	private function buildService(array $data): PeriodCloseAssistantService {
		$stub = new class($data) {

			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Data sets.
			 */
			public function __construct(array $data) {
				$this->data = $data;
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
			 * Fluent schema setter.
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
			 * Filter the active schema's rows by simple equality.
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

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$suspenseAgeing = $this->createMock(SuspenseAgeingService::class);
		$suspenseAgeing->method('agedUnmatchedItems')->willReturn(
			[
				'items' => [],
				'count' => 0,
				'oldestDaysOutstanding' => 0,
				'totalAmountCents' => 0,
			]
		);

		return new PeriodCloseAssistantService(
			appConfig: $appConfig,
			suspenseAgeing: $suspenseAgeing,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Open AP draft transactions are detected with count + total (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testDetectsOpenApTransactions(): void {
		$service = $this->buildService(
			[
				'GLTransaction' => [
					['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2026-01', 'state' => 'draft'],
					['id' => 'txn-2', 'administrationId' => 'adm-1', 'periodId' => '2026-01', 'state' => 'draft'],
					// Posted — must NOT count as open.
					['id' => 'txn-3', 'administrationId' => 'adm-1', 'periodId' => '2026-01', 'state' => 'posted'],
				],
				'GLLine' => [
					['transactionId' => 'txn-1', 'periodId' => '2026-01', 'subLedgerType' => 'ap', 'amount' => 1200.0],
					['transactionId' => 'txn-2', 'periodId' => '2026-01', 'subLedgerType' => 'ap', 'amount' => 4000.0],
					['transactionId' => 'txn-3', 'periodId' => '2026-01', 'subLedgerType' => 'ap', 'amount' => 999.0],
				],
			]
		);

		$result = $service->detectOpenSubLedger('adm-1', '2026-01', 'ap');
		self::assertSame(2, $result['count']);
		self::assertSame(5200.0, $result['total']);

	}//end testDetectsOpenApTransactions()

	/**
	 * Outstanding pending expense claims are detected and totalled (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testDetectsOutstandingExpenseClaims(): void {
		$service = $this->buildService(
			[
				'ExpenseClaimEntry' => [
					['administrationId' => 'adm-1', 'approvalState' => 'pending', 'totalAmount' => 150.0],
					['administrationId' => 'adm-1', 'approvalState' => 'pending', 'totalAmount' => 50.0],
					['administrationId' => 'adm-1', 'approvalState' => 'approved', 'totalAmount' => 999.0],
				],
			]
		);

		$result = $service->detectOutstandingExpenseClaims('adm-1');
		self::assertSame(2, $result['count']);
		self::assertSame(200.0, $result['total']);

	}//end testDetectsOutstandingExpenseClaims()

	/**
	 * Bank statements with movements but no posted GL match are flagged (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testDetectsUnreconciledBankReceipts(): void {
		$service = $this->buildService(
			[
				'BankStatement' => [
					['id' => 'st-1', 'administrationId' => 'adm-1', 'statementDate' => '2026-01-10', 'transactionCount' => 5],
					['id' => 'st-2', 'administrationId' => 'adm-1', 'statementDate' => '2026-01-20', 'transactionCount' => 0],
					['id' => 'st-3', 'administrationId' => 'adm-1', 'statementDate' => '2026-02-02', 'transactionCount' => 3],
				],
				'GLTransaction' => [],
			]
		);

		// Only st-1 qualifies: st-2 has no movements, st-3 is after the period end.
		$result = $service->detectUnreconciledBankReceipts('adm-1', '2026-01', '2026-01-31');
		self::assertSame(1, $result['count']);

	}//end testDetectsUnreconciledBankReceipts()

	/**
	 * A clean period produces an empty flag list (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testCleanPeriodProducesNoFlags(): void {
		$service = $this->buildService(['GLTransaction' => [], 'GLLine' => [], 'BankStatement' => [], 'ExpenseClaimEntry' => []]);
		$flags = $service->analyse('adm-1', '2026-01', '2026-01-31');
		self::assertSame([], $flags);

	}//end testCleanPeriodProducesNoFlags()

	/**
	 * The generateFlags() helper formats AP detections as warnings with a euro total (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testGenerateFlagsFormatsApWarning(): void {
		$service = $this->buildService([]);
		$flags = $service->generateFlags(
			[
				'ap' => ['count' => 3, 'total' => 5200.0],
				'ar' => ['count' => 0, 'total' => 0.0],
				'bank' => ['count' => 0, 'total' => 0.0],
				'expense-claims' => ['count' => 0, 'total' => 0.0],
			]
		);

		self::assertCount(1, $flags);
		self::assertSame('warning', $flags[0]['severity']);
		self::assertSame('ap', $flags[0]['category']);
		self::assertStringContainsString('3 outstanding AP', $flags[0]['message']);
		self::assertStringContainsString('€5,200.00', $flags[0]['message']);
		self::assertNotEmpty($flags[0]['detectedAt']);

	}//end testGenerateFlagsFormatsApWarning()

	/**
	 * The full analyse() pipeline emits one flag per non-empty category (REQ-PC-004).
	 *
	 * @return void
	 */
	public function testAnalyseEmitsFlagPerCategory(): void {
		$service = $this->buildService(
			[
				'GLTransaction' => [
					['id' => 'txn-1', 'administrationId' => 'adm-1', 'periodId' => '2026-01', 'state' => 'draft'],
				],
				'GLLine' => [
					['transactionId' => 'txn-1', 'periodId' => '2026-01', 'subLedgerType' => 'ar', 'amount' => 800.0],
				],
				'BankStatement' => [],
				'ExpenseClaimEntry' => [
					['administrationId' => 'adm-1', 'approvalState' => 'pending', 'totalAmount' => 75.0],
				],
			]
		);

		$flags = $service->analyse('adm-1', '2026-01', '2026-01-31');
		$categories = array_column($flags, 'category');
		self::assertContains('ar', $categories);
		self::assertContains('expense-claims', $categories);
		self::assertNotContains('ap', $categories);

	}//end testAnalyseEmitsFlagPerCategory()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
