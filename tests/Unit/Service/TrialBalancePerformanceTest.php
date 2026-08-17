<?php

/**
 * Performance test for TrialBalanceService at 10K+ accounts (REQ-TB-014).
 *
 * Wires TrialBalanceService to an in-memory ObjectService stub seeded with 10 000
 * accounts and 30 000 GLLines across 10 000 transactions in a single
 * administration / period, then asserts compute() returns inside two seconds.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-13-2
 * KNOWINGLY DANGLING until shillinq#500 — the < 2s budget it asserts
 * (archived REQ-TB-014) was never canonical.
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Asserts trial-balance compute() returns in < 2 s for 10 000 accounts
 * (REQ-TB-014). The algorithm is O(accounts + lines) over three scoped reads;
 * the stub mimics OR's findAll filters so the time spent in the service is
 * representative of real-world data shape (accounts + transactions + GLLines).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[Group('performance')]
final class TrialBalancePerformanceTest extends TestCase {
	/**
	 * Number of accounts to seed (REQ-TB-014 target).
	 */
	private const ACCOUNT_COUNT = 10_000;

	/**
	 * Number of GLTransactions to seed.
	 */
	private const TRANSACTION_COUNT = 10_000;

	/**
	 * Soft budget the service must beat (seconds) per REQ-TB-014.
	 */
	private const BUDGET_SECONDS = 2.0;

	/**
	 * Compute returns a balanced trial balance for 10 000 accounts under budget.
	 *
	 * @return void
	 */
	public function testComputeOf10kAccountsCompletesUnderTwoSeconds(): void {
		[$accounts, $transactions, $lines] = $this->seed();

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$stub = $this->newObjectServiceStub(
			accounts: $accounts,
			transactions: $transactions,
			lines: $lines,
		);
		$container->method('get')->willReturn($stub);

		$service = new TrialBalanceService(
			appConfig: $appConfig,
			calculator: new TrialBalanceCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

		$startedAt = microtime(true);
		$result = $service->compute('adm-perf', '2026-Q1');
		$elapsed = (microtime(true) - $startedAt);

		self::assertSame(self::ACCOUNT_COUNT, $result['total']);
		// For each (i % 5 == 0) account we booked a debit, otherwise nothing.
		// Lines are always paired debit/credit across two accounts so debit==credit.
		self::assertTrue(
			$result['isBalanced'],
			'Trial balance must be balanced for paired debit/credit fixtures.'
		);

		self::assertLessThan(
			self::BUDGET_SECONDS,
			$elapsed,
			sprintf(
				'TrialBalanceService::compute() took %.3fs for %d accounts; REQ-TB-014 budget is %.1fs.',
				$elapsed,
				self::ACCOUNT_COUNT,
				self::BUDGET_SECONDS
			)
		);

	}//end testComputeOf10kAccountsCompletesUnderTwoSeconds()

	/**
	 * Build the seed dataset (returned by reference so the caller can attach it to the stub).
	 *
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:array<int,array<string,mixed>>}
	 */
	private function seed(): array {
		$accounts = [];
		$transactions = [];
		$lines = [];

		for ($i = 0; $i < self::ACCOUNT_COUNT; $i++) {
			$accounts[] = [
				'accountNumber' => sprintf('%05d', ($i + 1000)),
				'name' => 'Account ' . $i,
				'accountType' => (($i % 2) === 0) ? 'assets' : 'liabilities',
				'currency' => 'EUR',
				'parentAccountNumber' => null,
				'administrationId' => 'adm-perf',
			];
		}

		// Paired debit/credit transactions across two accounts so the GL stays
		// balanced. Each transaction posts one debit + one credit line.
		for ($i = 0; $i < self::TRANSACTION_COUNT; $i++) {
			$debitIdx = ($i % self::ACCOUNT_COUNT);
			$creditIdx = (($i + 1) % self::ACCOUNT_COUNT);
			$txnId = 'txn-' . $i;
			$transactions[] = [
				'id' => $txnId,
				'administrationId' => 'adm-perf',
				'periodId' => '2026-Q1',
			];
			$lines[] = [
				'transactionId' => $txnId,
				'accountNumber' => $accounts[$debitIdx]['accountNumber'],
				'side' => 'debit',
				'amount' => 100.0,
				'periodId' => '2026-Q1',
			];
			$lines[] = [
				'transactionId' => $txnId,
				'accountNumber' => $accounts[$creditIdx]['accountNumber'],
				'side' => 'credit',
				'amount' => 100.0,
				'periodId' => '2026-Q1',
			];
		}

		return [$accounts, $transactions, $lines];
	}//end seed()

	/**
	 * Construct an anonymous ObjectService stub that mimics setRegister/setSchema/findAll
	 * with simple equality filter support — matches the shape exercised by TrialBalanceService.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account fixtures.
	 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
	 * @param array<int,array<string,mixed>> $lines GLLine fixtures.
	 *
	 * @return object
	 */
	private function newObjectServiceStub(array $accounts, array $transactions, array $lines): object {
		return new class($accounts, $transactions, $lines) {
			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Last selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $accounts Account fixtures.
			 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
			 * @param array<int,array<string,mixed>> $lines GLLine fixtures.
			 */
			public function __construct(array $accounts, array $transactions, array $lines) {
				$this->data = [
					'Account' => $accounts,
					'GLTransaction' => $transactions,
					'GLLine' => $lines,
				];
			}//end __construct()

			/**
			 * Fluent register setter (no-op for the stub).
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
			 * Return the rows for the active schema, filtered by simple equality.
			 *
			 * @param array<string,mixed> $params Query params.
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

	}//end newObjectServiceStub()
}//end class
