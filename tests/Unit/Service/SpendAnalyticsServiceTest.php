<?php

/**
 * Unit tests for SpendAnalyticsService.
 *
 * Proves two things a leaf that CONSUMES OR's aggregation-api must guarantee:
 *
 *  1. Query construction — every view builds a SINGLE-field groupBy query
 *     (metric=sum, the right field/filter/groupField) and dispatches it
 *     through `runAdhocByRef`. Multi-field groupBy is never requested, because
 *     OR does not honour it (routing decision, see design.md).
 *  2. Correct totals — over a KNOWN seeded set of APTransaction / GLLine rows,
 *     the spend-by-supplier / category / cost-centre / period group sums and
 *     grand totals equal the hand-computed numbers.
 *
 * The `InMemoryAggregationRunner` double reproduces exactly the slice of OR's
 * engine the service relies on: single scalar-field GROUP BY sum with the
 * scalar-equality + `in` filter operators. OR's own engine correctness is
 * covered by openregister's AggregationRunner integration suite; here we prove
 * the leaf asks the right question and shapes the answer correctly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Shillinq\Service\SpendAnalyticsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Faithful in-memory double of OR's aggregation runner: single-field grouped
 * SUM with scalar-equality + `in` filters. Records the last query it received
 * so tests can assert correct query construction.
 */
final class InMemoryAggregationRunner {
	/**
	 * Seeded rows keyed by schema ref.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The last AggregationQuery received, keyed by schema ref.
	 *
	 * @var array<string,AggregationQuery>
	 */
	public array $lastQuery = [];

	/**
	 * Seed a schema's rows.
	 *
	 * @param string $schema Schema ref.
	 * @param array<int,array<string,mixed>> $rows Object rows.
	 *
	 * @return void
	 */
	public function seed(string $schema, array $rows): void {
		$this->rows[$schema] = $rows;

	}//end seed()

	/**
	 * Ad-hoc aggregation by ref — single-field grouped SUM.
	 *
	 * @param string $registerRef Register ref (unused in the double).
	 * @param string $schemaRef Schema ref.
	 * @param AggregationQuery $query The query.
	 *
	 * @return array<string,mixed> `{ groups:[{key,value}], backend, cached }`.
	 */
	public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array {
		$this->lastQuery[$schemaRef] = $query;

		$groupField = $query->getGroupByField();
		$field = $query->field;
		$rows = ($this->rows[$schemaRef] ?? []);

		$buckets = [];
		foreach ($rows as $row) {
			if ($this->matches(row: $row, filter: $query->filter) === false) {
				continue;
			}

			$key = (string)($row[$groupField] ?? '');
			if (isset($buckets[$key]) === false) {
				$buckets[$key] = 0.0;
			}

			$buckets[$key] += (float)($row[$field] ?? 0);
		}

		$groups = [];
		foreach ($buckets as $key => $value) {
			$groups[] = ['key' => $key, 'value' => $value];
		}

		return ['groups' => $groups, 'backend' => 'php-fallback', 'cached' => false];
	}//end runAdhocByRef()

	/**
	 * Apply the honoured filter operators: scalar equality + `in`.
	 *
	 * @param array<string,mixed> $row The row.
	 * @param array<string,mixed> $filter The filter map.
	 *
	 * @return bool Whether the row matches.
	 */
	private function matches(array $row, array $filter): bool {
		foreach ($filter as $key => $cond) {
			$value = ($row[$key] ?? null);
			if (is_array($cond) === true && isset($cond['in']) === true) {
				if (in_array($value, $cond['in'], true) === false) {
					return false;
				}

				continue;
			}

			if ($value !== $cond) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class

/**
 * @covers \OCA\Shillinq\Service\SpendAnalyticsService
 */
final class SpendAnalyticsServiceTest extends TestCase {
	/**
	 * Build the service around the in-memory runner.
	 *
	 * @param InMemoryAggregationRunner $runner The seeded runner double.
	 *
	 * @return SpendAnalyticsService
	 */
	private function makeService(InMemoryAggregationRunner $runner): SpendAnalyticsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($runner);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		return new SpendAnalyticsService(container: $container, appConfig: $appConfig, logger: $logger);
	}//end makeService()

	/**
	 * A known set of AP invoices spanning suppliers + states.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function apTransactions(): array {
		return [
			// Vendor V1: 100.00 + 50.00 committed (issued/paid) = 150.00.
			['vendorId' => 'V1', 'totalAmount' => 100.00, 'state' => 'issued'],
			['vendorId' => 'V1', 'totalAmount' => 50.00,  'state' => 'paid'],
			// Vendor V2: 200.00 committed (overdue) = 200.00.
			['vendorId' => 'V2', 'totalAmount' => 200.00, 'state' => 'overdue'],
			// Vendor V2: 999.00 DRAFT — excluded from committed spend.
			['vendorId' => 'V2', 'totalAmount' => 999.00, 'state' => 'draft'],
			// Vendor V3: 40.00 VOIDED — excluded.
			['vendorId' => 'V3', 'totalAmount' => 40.00,  'state' => 'voided'],
		];

	}//end apTransactions()

	/**
	 * A known set of GL debit AP expense lines across accounts / cost centres
	 * / periods, plus non-matching noise (credit line, non-ap sub-ledger).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function glLines(): array {
		return [
			['accountNumber' => '4000', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 60.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['accountNumber' => '4000', 'costCenterCode' => 'CC20', 'periodId' => '2026-01', 'amount' => 40.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['accountNumber' => '4100', 'costCenterCode' => 'CC10', 'periodId' => '2026-02', 'amount' => 25.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			// Credit line — excluded (side != debit).
			['accountNumber' => '1600', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 125.00, 'side' => 'credit', 'subLedgerType' => 'ap'],
			// Non-AP sub-ledger — excluded.
			['accountNumber' => '4000', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 500.00, 'side' => 'debit', 'subLedgerType' => 'ar'],
		];

	}//end glLines()

	/**
	 * spend-by-supplier sums gross invoice totals over committed states.
	 */
	public function testSpendBySupplierCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('APTransaction', $this->apTransactions());
		$service = $this->makeService($runner);

		$result = $service->spendBySupplier();

		$this->assertSame('supplier', $result['dimension']);
		$this->assertSame('php-fallback', $result['backend']);

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		// V1 = 100 + 50 = 150; V2 = 200 (draft 999 excluded); V3 voided absent.
		$this->assertSame(150.0, $byKey['V1']);
		$this->assertSame(200.0, $byKey['V2']);
		$this->assertArrayNotHasKey('V3', $byKey);
		$this->assertSame(350.0, $result['total']);

		// Routing guard: single-field groupBy only.
		$query = $runner->lastQuery['APTransaction'];
		$this->assertSame('sum', $query->metric);
		$this->assertSame('totalAmount', $query->field);
		$this->assertSame('vendorId', $query->getGroupByField());
		$this->assertSame(['field' => 'vendorId'], $query->groupBy);
	}//end testSpendBySupplierCorrectTotals()

	/**
	 * spend-by-category sums debit AP expense postings by GL account.
	 */
	public function testSpendByCategoryCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$result = $service->spendByCategory();

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		// 4000 = 60 + 40 = 100 (credit 125 + AR 500 excluded); 4100 = 25.
		$this->assertSame(100.0, $byKey['4000']);
		$this->assertSame(25.0, $byKey['4100']);
		$this->assertArrayNotHasKey('1600', $byKey);
		$this->assertSame(125.0, $result['total']);

		$query = $runner->lastQuery['GLLine'];
		$this->assertSame('sum', $query->metric);
		$this->assertSame('amount', $query->field);
		$this->assertSame('accountNumber', $query->getGroupByField());
		$this->assertSame(['side' => 'debit', 'subLedgerType' => 'ap'], $query->filter);
	}//end testSpendByCategoryCorrectTotals()

	/**
	 * spend-by-cost-centre sums the same posting slice by costCenterCode.
	 */
	public function testSpendByCostCentreCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$result = $service->spendByCostCentre();

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		// CC10 = 60 + 25 = 85; CC20 = 40.
		$this->assertSame(85.0, $byKey['CC10']);
		$this->assertSame(40.0, $byKey['CC20']);
		$this->assertSame(125.0, $result['total']);
		$this->assertSame('costCenterCode', $runner->lastQuery['GLLine']->getGroupByField());
	}//end testSpendByCostCentreCorrectTotals()

	/**
	 * spend-by-period sums the same posting slice by fiscal period.
	 */
	public function testSpendByPeriodCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$result = $service->spendByPeriod();

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		// 2026-01 = 60 + 40 = 100; 2026-02 = 25.
		$this->assertSame(100.0, $byKey['2026-01']);
		$this->assertSame(25.0, $byKey['2026-02']);
		$this->assertSame(125.0, $result['total']);
		$this->assertSame('periodId', $runner->lastQuery['GLLine']->getGroupByField());
	}//end testSpendByPeriodCorrectTotals()

	/**
	 * When OR's aggregation runner is unavailable, the service raises rather
	 * than silently returning an empty/zero result (no orphaned-capability
	 * fail-open).
	 */
	public function testRaisesWhenRunnerUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OR'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$service = new SpendAnalyticsService(container: $container, appConfig: $appConfig, logger: $logger);

		$this->expectException(RuntimeException::class);
		$service->spendBySupplier();
	}//end testRaisesWhenRunnerUnavailable()
}//end class
