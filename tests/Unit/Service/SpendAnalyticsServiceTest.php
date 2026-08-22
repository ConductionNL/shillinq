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
use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
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
	 * @param bool $backfillProven Whether the GLLine administration backfill gate is open.
	 *
	 * @return SpendAnalyticsService
	 */
	private function makeService(
		InMemoryAggregationRunner $runner,
		bool $backfillProven = true,
		?string $gateOverride = null
	): SpendAnalyticsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($runner);

		// `$gateOverride` exists so a STALE, NON-EMPTY version can be tested.
		// Without it this helper only ever produces '' or the current version,
		// so the exact-match comparison in assertGlScopeIsEnforceable() is
		// never exercised against a wrong-but-present value — and a
		// `!== ''` check would pass every test here while leaving a proof
		// written by an older contract looking valid.
		$gate = '';
		if ($gateOverride !== null) {
			$gate = $gateOverride;
		} elseif ($backfillProven === true) {
			$gate = GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION;
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($gate): string {
				if ($key === GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY) {
					return $gate;
				}

				return 'shillinq';
			}
		);

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
			['administrationId' => 'ADM-001', 'vendorId' => 'V1', 'totalAmount' => 100.00, 'state' => 'issued'],
			['administrationId' => 'ADM-001', 'vendorId' => 'V1', 'totalAmount' => 50.00,  'state' => 'paid'],
			// Vendor V2: 200.00 committed (overdue) = 200.00.
			['administrationId' => 'ADM-001', 'vendorId' => 'V2', 'totalAmount' => 200.00, 'state' => 'overdue'],
			// Vendor V2: 999.00 DRAFT — excluded from committed spend.
			['administrationId' => 'ADM-001', 'vendorId' => 'V2', 'totalAmount' => 999.00, 'state' => 'draft'],
			// Vendor V3: 40.00 VOIDED — excluded.
			['administrationId' => 'ADM-001', 'vendorId' => 'V3', 'totalAmount' => 40.00,  'state' => 'voided'],
			// ANOTHER TENANT'S committed invoices. Same schema, same register,
			// same committed states — the ONLY thing separating them is the
			// administrationId filter the service is now required to send.
			// Deliberately larger than every ADM-001 figure so a missing
			// filter cannot pass any of the assertions by coincidence.
			['administrationId' => 'ADM-999', 'vendorId' => 'V1', 'totalAmount' => 7000.00, 'state' => 'issued'],
			['administrationId' => 'ADM-999', 'vendorId' => 'V9', 'totalAmount' => 8000.00, 'state' => 'paid'],
		];

	}//end apTransactions()

	/**
	 * A known set of GL debit AP expense lines across accounts / cost centres
	 * / periods, plus non-matching noise (credit line, non-ap sub-ledger) —
	 * and, crucially, a SECOND administration's lines.
	 *
	 * Every line carries `administrationId`, because that is exactly what
	 * `glline-administration-scope` denormalised onto GLLine and what the
	 * three GL-backed views now filter on. ADM-B's figures are deliberately an
	 * order of magnitude larger than every ADM-A figure, so a service that
	 * dropped the filter could not pass any assertion below by coincidence —
	 * it would fold 9000/7000/2000 into ADM-A's totals and surface ADM-B's
	 * account 4900, cost centre CC90 and period 2026-03 as groups the caller
	 * has no membership to see.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function glLines(): array {
		return [
			['administrationId' => 'ADM-A', 'accountNumber' => '4000', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 60.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['administrationId' => 'ADM-A', 'accountNumber' => '4000', 'costCenterCode' => 'CC20', 'periodId' => '2026-01', 'amount' => 40.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['administrationId' => 'ADM-A', 'accountNumber' => '4100', 'costCenterCode' => 'CC10', 'periodId' => '2026-02', 'amount' => 25.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			// Credit line — excluded (side != debit).
			['administrationId' => 'ADM-A', 'accountNumber' => '1600', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 125.00, 'side' => 'credit', 'subLedgerType' => 'ap'],
			// Non-AP sub-ledger — excluded.
			['administrationId' => 'ADM-A', 'accountNumber' => '4000', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 500.00, 'side' => 'debit', 'subLedgerType' => 'ar'],
			// ANOTHER ADMINISTRATION'S committed postings. Same schema, same
			// register, same posting slice — the ONLY thing separating them is
			// the administrationId filter this change added.
			['administrationId' => 'ADM-B', 'accountNumber' => '4000', 'costCenterCode' => 'CC10', 'periodId' => '2026-01', 'amount' => 9000.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['administrationId' => 'ADM-B', 'accountNumber' => '4900', 'costCenterCode' => 'CC90', 'periodId' => '2026-03', 'amount' => 7000.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
			['administrationId' => 'ADM-B', 'accountNumber' => '4100', 'costCenterCode' => 'CC90', 'periodId' => '2026-02', 'amount' => 2000.00, 'side' => 'debit', 'subLedgerType' => 'ap'],
		];

	}//end glLines()

	/**
	 * spend-by-supplier sums gross invoice totals over committed states.
	 */
	public function testSpendBySupplierCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('APTransaction', $this->apTransactions());
		$service = $this->makeService($runner);

		$result = $service->spendBySupplier(administrationId: 'ADM-001');

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
	 * THE TENANT-ISOLATION CONTROL (gate-7). The supplier aggregation must
	 * carry the caller's administration into the filter it sends to OR, and
	 * the totals it returns must contain no other administration's invoices.
	 *
	 * Written so it FAILS on the pre-fix service, twice over: that version's
	 * filter was `['state' => ['in' => …]]` with no administration term, so
	 * the filter assertion fails outright, and the totals it produced summed
	 * ADM-999's 7000 into V1 (7150, not 150) and surfaced a V9 group that the
	 * caller has no membership to see.
	 */
	public function testSpendBySupplierExcludesOtherAdministrations(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('APTransaction', $this->apTransactions());
		$service = $this->makeService($runner);

		$result = $service->spendBySupplier(administrationId: 'ADM-001');

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		// ADM-999's 7000 must not be folded into V1, and its V9 must be absent.
		$this->assertSame(150.0, $byKey['V1']);
		$this->assertArrayNotHasKey('V9', $byKey);
		$this->assertSame(350.0, $result['total']);

		// The scope must reach OR as a filter term, not merely be checked and
		// dropped upstream: an aggregation is executed by the database, so a
		// scope that never enters the query never narrows anything.
		$filter = $runner->lastQuery['APTransaction']->filter;
		$this->assertArrayHasKey('administrationId', $filter);
		$this->assertSame('ADM-001', $filter['administrationId']);
	}//end testSpendBySupplierExcludesOtherAdministrations()

	/**
	 * The same seeded set read as a different tenant returns that tenant's
	 * figures — proving the filter is the caller's administration and not a
	 * constant that happens to match the fixture.
	 */
	public function testSpendBySupplierIsScopedToTheAdministrationAsked(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('APTransaction', $this->apTransactions());
		$service = $this->makeService($runner);

		$result = $service->spendBySupplier(administrationId: 'ADM-999');

		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		$this->assertSame(7000.0, $byKey['V1']);
		$this->assertSame(8000.0, $byKey['V9']);
		$this->assertArrayNotHasKey('V2', $byKey);
		$this->assertSame(15000.0, $result['total']);
	}//end testSpendBySupplierIsScopedToTheAdministrationAsked()

	/**
	 * spend-by-category sums debit AP expense postings by GL account.
	 */
	public function testSpendByCategoryCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$result = $service->spendByCategory(administrationId: 'ADM-A');

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
		$this->assertSame(
			['administrationId' => 'ADM-A', 'side' => 'debit', 'subLedgerType' => 'ap'],
			$query->filter
		);
	}//end testSpendByCategoryCorrectTotals()

	/**
	 * spend-by-cost-centre sums the same posting slice by costCenterCode.
	 */
	public function testSpendByCostCentreCorrectTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$result = $service->spendByCostCentre(administrationId: 'ADM-A');

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

		$result = $service->spendByPeriod(administrationId: 'ADM-A');

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
		$service->spendBySupplier(administrationId: 'ADM-001');
	}//end testRaisesWhenRunnerUnavailable()

	/**
	 * THE NEGATIVE CONTROL (REQ-GLS-003). A member of administration A must
	 * not see administration B's category / cost-centre / period totals.
	 *
	 * This is the read that used to be open. `GLLine` declared no
	 * administration property at all, so these three views aggregated EVERY
	 * administration in the register while the fourth (spend-by-supplier) was
	 * correctly scoped — the service looked scoped and was not. Written to
	 * FAIL on the pre-fix service in every dimension at once: without the
	 * filter, account 4000 sums 60 + 40 + ADM-B's 9000, cost centre CC10 sums
	 * 85 + 9000, period 2026-01 sums 100 + 9000, and ADM-B's 4900 / CC90 /
	 * 2026-03 all surface as groups the caller has no membership to see.
	 *
	 * @return void
	 */
	public function testGlBackedViewsExcludeOtherAdministrations(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$category = $this->byKey($service->spendByCategory(administrationId: 'ADM-A'));
		$costCentre = $this->byKey($service->spendByCostCentre(administrationId: 'ADM-A'));
		$period = $this->byKey($service->spendByPeriod(administrationId: 'ADM-A'));

		// ADM-B's amounts must not be folded into any shared group key...
		$this->assertSame(100.0, $category['4000']);
		$this->assertSame(85.0, $costCentre['CC10']);
		$this->assertSame(100.0, $period['2026-01']);

		// ...and ADM-B's own keys must not appear at all.
		$this->assertArrayNotHasKey('4900', $category);
		$this->assertArrayNotHasKey('CC90', $costCentre);
		$this->assertArrayNotHasKey('2026-03', $period);

		// The scope must reach OR as a FILTER TERM, not merely be checked and
		// dropped upstream: an aggregation is executed by the database, so a
		// scope that never enters the query never narrows anything.
		$this->assertSame('ADM-A', $runner->lastQuery['GLLine']->filter['administrationId']);
	}//end testGlBackedViewsExcludeOtherAdministrations()

	/**
	 * THE POSITIVE CONTROL (REQ-GLS-003). The scoped views must still return
	 * ROWS and REAL TOTALS for a correctly-backfilled administration.
	 *
	 * This is the control the forbidden naive fix would have failed. Adding
	 * `administrationId` to a GLLine filter BEFORE the backfill addresses a
	 * property those rows do not carry; an unmatched key matches nothing for
	 * every value, so all three views would have read ZERO — a wrong number
	 * that looks exactly like "this administration has no spend", which is
	 * worse than the exposure it pretends to close. Asserting non-empty groups
	 * and exact totals is what tells those two states apart.
	 *
	 * @return void
	 */
	public function testScopedViewsStillReturnRowsAndRealTotals(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		foreach (['category', 'costCentre', 'period'] as $dimension) {
			$result = match ($dimension) {
				'category' => $service->spendByCategory(administrationId: 'ADM-A'),
				'costCentre' => $service->spendByCostCentre(administrationId: 'ADM-A'),
				default => $service->spendByPeriod(administrationId: 'ADM-A'),
			};

			$this->assertNotSame([], $result['groups'], $dimension . ' returned no rows at all');
			$this->assertGreaterThan(0.0, $result['total'], $dimension . ' silently totalled zero');
			$this->assertSame(125.0, $result['total'], $dimension . ' total is not the hand-computed figure');
		}

		// And the same seeded set read as the OTHER tenant returns that
		// tenant's figures — proving the filter is the caller's administration
		// and not a constant that happens to match the first fixture.
		$this->assertSame(18000.0, $service->spendByCategory(administrationId: 'ADM-B')['total']);
	}//end testScopedViewsStillReturnRowsAndRealTotals()

	/**
	 * The GL-backed views RAISE while the backfill gate is shut, rather than
	 * serving unscoped totals (the original exposure) or a filtered query over
	 * rows that cannot match it (a silent zero).
	 *
	 * @return void
	 */
	public function testGlBackedViewsRefuseWhileTheBackfillIsUnproven(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner, backfillProven: false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('backfill is not proven complete');

		$service->spendByCategory(administrationId: 'ADM-A');
	}//end testGlBackedViewsRefuseWhileTheBackfillIsUnproven()

	/**
	 * A proof written by an OLDER contract version is not a proof.
	 *
	 * This is the reader half of the version contract. The writer half —
	 * that a stale value is destroyed and replaced when it can be re-proven —
	 * lives in BackfillGlLineAdministrationTest. Both halves are needed:
	 * REQ-GLS-003 makes the gate a VERSION rather than a boolean precisely so
	 * that adding a new GLLine writer invalidates every deployment's stored
	 * proof, and that only holds if the READ is an exact match. A `!== ''`
	 * check would satisfy every other test in this file while treating a
	 * proof from a superseded contract as current — the filter would then
	 * switch on over rows the newer writer never scoped.
	 *
	 * @return void
	 */
	public function testAProofFromASupersededContractVersionDoesNotCount(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner, gateOverride: 'v0-superseded');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('backfill is not proven complete');

		$service->spendByCategory(administrationId: 'ADM-A');
	}//end testAProofFromASupersededContractVersionDoesNotCount()

	/**
	 * The supplier view is unaffected by the GLLine gate: it reads
	 * APTransaction, which has always declared `administrationId`.
	 *
	 * @return void
	 */
	public function testSupplierViewIsUnaffectedByTheGlLineGate(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('APTransaction', $this->apTransactions());
		$service = $this->makeService($runner, backfillProven: false);

		$this->assertSame(350.0, $service->spendBySupplier(administrationId: 'ADM-001')['total']);
	}//end testSupplierViewIsUnaffectedByTheGlLineGate()

	/**
	 * An empty administration is refused rather than filtered on, because
	 * `administrationId = ''` matches nothing — the same silent zero by a
	 * different route.
	 *
	 * @return void
	 */
	public function testEmptyAdministrationIsRefused(): void {
		$runner = new InMemoryAggregationRunner();
		$runner->seed('GLLine', $this->glLines());
		$service = $this->makeService($runner);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('requires a non-empty administrationId');

		$service->spendByPeriod(administrationId: '  ');
	}//end testEmptyAdministrationIsRefused()

	/**
	 * Flatten a shaped result to `key => amount`.
	 *
	 * @param array<string,mixed> $result The service payload.
	 *
	 * @return array<string,float>
	 */
	private function byKey(array $result): array {
		$byKey = [];
		foreach ($result['groups'] as $group) {
			$byKey[$group['key']] = $group['amount'];
		}

		return $byKey;
	}//end byKey()
}//end class
