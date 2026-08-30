<?php

/**
 * Unit tests for BudgetChartSeriesService.
 *
 * Covers `budget-charts` task group 2 (REQ-BCH-003, REQ-BCH-004,
 * REQ-BCH-008): composition-only assertions (right collaborators, right
 * shape), the `unprojectable` never-a-zero-line guarantee at the
 * orchestration boundary, the `annualBudgetId` override threading, and the
 * query-count regression proving `BudgetVsActualsReader::loadContext()` and
 * `BudgetProjectionReader::loadContext()` are each called EXACTLY ONCE per
 * request regardless of how many accounts/LedgerGroups the administration
 * has — the specific failure mode a per-entity call pattern would produce
 * (see the class docblock's "16-18 queries/page" cross-reference).
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
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetChartSeriesService;
use OCA\Shillinq\Service\BudgetProjectionCalculator;
use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the orchestration layer's composition, unprojectable pass-through
 * and flat query cost.
 */
final class BudgetChartSeriesServiceTest extends TestCase {

	/**
	 * Build the service over a seeded fixture store, returning both the
	 * service and the SHARED call-counting decorator (used by both readers
	 * AND the service's own AnnualBudget resolution) so a test can assert
	 * on `$decorator->findAllCalls`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: BudgetChartSeriesService, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildService(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$vsActualsReader = new BudgetVsActualsReader(appConfig: $appConfig, logger: new NullLogger(), objectService: $decorator);
		$projectionReader = new BudgetProjectionReader(appConfig: $appConfig, logger: new NullLogger(), objectService: $decorator);

		$service = new BudgetChartSeriesService(
			vsActualsReader: $vsActualsReader,
			vsActualsCalculator: new BudgetVsActualsCalculator(),
			projectionReader: $projectionReader,
			projectionCalculator: new BudgetProjectionCalculator(),
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		return [$service, $decorator];

	}//end buildService()

	/**
	 * One administration: account 1000 (`revenue`), 6 months of posted GL
	 * activity growing at a steady rate (enough valid steps to project),
	 * one LedgerGroup ("Omzet") covering it, one default AnnualBudget with
	 * a BudgetLine against that LedgerGroup.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		$transactions = [];
		$lines = [];
		// Steady 10% monthly growth, Jan-Jun 2027 — enough valid steps
		// (5, >= MIN_VALID_STEPS and >= OUTLIER_TRIM_MIN_STEPS) for a real
		// growth-rate fit, so Jul onward projects rather than reading
		// unprojectable for a reason unrelated to what this test checks.
		$amount = 1000.00;
		for ($m = 1; $m <= 6; $m++) {
			$txId = 'tx-' . $m;
			$transactions[] = [
				'id' => $txId,
				'transactionNumber' => 'GL-2027-' . $m,
				'administrationId' => 'adm-1',
				'state' => 'posted',
				'postingDate' => sprintf('2027-%02d-15', $m),
			];
			$lines[] = ['transactionId' => $txId, 'accountNumber' => '1000', 'side' => 'credit', 'amount' => $amount];
			$amount *= 1.10;
		}

		return [
			'Account' => [
				['accountNumber' => '1000', 'administrationId' => 'adm-1', 'accountType' => 'revenue'],
			],
			'GLTransaction' => $transactions,
			'GLLine' => $lines,
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'ledger-group-omzet'],
					'name' => 'Omzet',
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => null,
					'accountRanges' => [['from' => '1000', 'to' => '1099']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
			'AnnualBudget' => [
				['id' => 'ab-2027', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => true, 'state' => 'active'],
			],
			'BudgetLine' => [
				[
					'id' => 'bl-1',
					'administrationId' => 'adm-1',
					'annualBudgetId' => 'ab-2027',
					'ledgerGroupId' => 'lg-1',
					'month01Amount' => 90000,
					'month02Amount' => 90000,
					'month03Amount' => 90000,
					'month04Amount' => 90000,
					'month05Amount' => 90000,
					'month06Amount' => 90000,
					'month07Amount' => 90000,
					'month08Amount' => 90000,
					'month09Amount' => 90000,
					'month10Amount' => 90000,
					'month11Amount' => 90000,
					'month12Amount' => 90000,
				],
			],
		];

	}//end fixture()

	/**
	 * `resolveSeries()` calls each reader's `loadContext()` EXACTLY ONCE
	 * (5 for BudgetVsActualsReader + 4 for BudgetProjectionReader), plus
	 * exactly 1 more for the single fiscal year's AnnualBudget resolution
	 * — 10 total, and this total does NOT grow when the fixture carries
	 * many more accounts (REQ-BCH-008 scenario 2).
	 *
	 * @return void
	 */
	public function testQueryCountStaysFlatRegardlessOfAccountCount(): void {
		[$service, $decorator] = $this->buildService($this->fixture());

		$service->resolveSeries('adm-1', '2027-01', '2027-08');

		$this->assertSame(10, $decorator->findAllCalls);

		// Now with 20 accounts instead of 1 — the total must not grow.
		$data = $this->fixture();
		for ($a = 1; $a < 20; $a++) {
			$accountNumber = (string)(1000 + $a);
			$data['Account'][] = ['accountNumber' => $accountNumber, 'administrationId' => 'adm-1', 'accountType' => 'expenses'];
			for ($m = 1; $m <= 6; $m++) {
				$txId = 'tx-' . $a . '-' . $m;
				$data['GLTransaction'][] = [
					'id' => $txId,
					'transactionNumber' => 'GL-' . $a . '-' . $m,
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => sprintf('2027-%02d-01', $m),
				];
				$data['GLLine'][] = ['transactionId' => $txId, 'accountNumber' => $accountNumber, 'side' => 'debit', 'amount' => 10.00];
			}
		}

		[$service2, $decorator2] = $this->buildService($data);
		$service2->resolveSeries('adm-1', '2027-01', '2027-08');

		$this->assertSame(10, $decorator2->findAllCalls);

	}//end testQueryCountStaysFlatRegardlessOfAccountCount()

	/**
	 * An account with only ONE month of history (< MIN_VALID_STEPS) never
	 * renders a projected month as a fabricated amount — the trend entry
	 * carries `kind: unprojectable` with NO `amount` key at all
	 * (REQ-BCH-004 at the orchestration boundary — the arithmetic itself is
	 * BudgetProjectionCalculator's own already-tested territory; this proves
	 * the service does not paper over an unprojectable result with a zero).
	 *
	 * @return void
	 */
	public function testUnprojectableMonthCarriesNoAmountKey(): void {
		$data = $this->fixture();
		// Cut GL history down to ONE month — well under MIN_VALID_STEPS.
		$data['GLTransaction'] = [$data['GLTransaction'][0]];
		$data['GLLine'] = [$data['GLLine'][0]];

		[$service] = $this->buildService($data);

		$result = $service->resolveSeries('adm-1', '2027-01', '2027-08');

		$account = $this->accountByNumber($result, '1000');
		$this->assertSame('unprojectable', $account['trend']['2027-08']['kind']);
		$this->assertArrayNotHasKey('amount', $account['trend']['2027-08']);
		$this->assertSame('insufficient-data', $account['trend']['2027-08']['reason']);

	}//end testUnprojectableMonthCarriesNoAmountKey()

	/**
	 * A projected month, once enough history exists, carries a real amount
	 * (the positive control for the test above — proves the service CAN
	 * project, so the prior test's absence of `amount` is meaningful and
	 * not just a permanently-broken code path).
	 *
	 * @return void
	 */
	public function testProjectedMonthCarriesARealAmount(): void {
		[$service] = $this->buildService($this->fixture());

		$result = $service->resolveSeries('adm-1', '2027-01', '2027-08');

		$account = $this->accountByNumber($result, '1000');
		$this->assertSame('projected', $account['trend']['2027-08']['kind']);
		$this->assertIsInt($account['trend']['2027-08']['amount']);
		// Credits are signed negative (BudgetVsActualsReader's own
		// convention, mirrored by BudgetProjectionReader) — growing revenue
		// compounds to a LARGER-MAGNITUDE negative number, not a positive
		// one. Compare against the last actual month's own magnitude
		// instead of assuming a sign.
		$this->assertLessThan($account['trend']['2027-06']['amount'], $account['trend']['2027-08']['amount']);

	}//end testProjectedMonthCarriesARealAmount()

	/**
	 * The `actual`-kind trend amount for a posted month equals
	 * BudgetVsActualsReader's own bucketed GL amount for that month
	 * (REQ-BCH-003 scenario 1).
	 *
	 * @return void
	 */
	public function testActualAmountMatchesBudgetVsActualsReader(): void {
		[$service] = $this->buildService($this->fixture());

		$result = $service->resolveSeries('adm-1', '2027-01', '2027-08');

		$account = $this->accountByNumber($result, '1000');
		$this->assertSame('actual', $account['trend']['2027-01']['kind']);
		// 1000.00 EUR credited -> -100000 signed cents in BudgetVsActualsReader's
		// convention (credit is negated), matching a revenue account's own sign.
		$this->assertSame(-100000, $account['trend']['2027-01']['amount']);

	}//end testActualAmountMatchesBudgetVsActualsReader()

	/**
	 * The LedgerGroup's own budgeted series comes from its `BudgetLine`,
	 * and an explicit `annualBudgetId` override swaps it for a DIFFERENT
	 * budget's own line (REQ-BCH-003 scenario 3).
	 *
	 * @return void
	 */
	public function testAnnualBudgetIdOverrideSwapsTheBudgetedSeries(): void {
		$data = $this->fixture();
		$data['AnnualBudget'][] = ['id' => 'ab-2027-alt', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => false, 'state' => 'draft'];
		$data['BudgetLine'][] = [
			'id' => 'bl-2',
			'administrationId' => 'adm-1',
			'annualBudgetId' => 'ab-2027-alt',
			'ledgerGroupId' => 'lg-1',
			'month01Amount' => 555500,
			'month02Amount' => 555500,
			'month03Amount' => 555500,
			'month04Amount' => 555500,
			'month05Amount' => 555500,
			'month06Amount' => 555500,
			'month07Amount' => 555500,
			'month08Amount' => 555500,
			'month09Amount' => 555500,
			'month10Amount' => 555500,
			'month11Amount' => 555500,
			'month12Amount' => 555500,
		];

		[$service] = $this->buildService($data);

		$default = $service->resolveSeries('adm-1', '2027-01', '2027-01');
		$override = $service->resolveSeries('adm-1', '2027-01', '2027-01', 'ab-2027-alt');

		$defaultGroup = $this->groupByKey($default, 'lg-1');
		$overrideGroup = $this->groupByKey($override, 'lg-1');

		$this->assertSame(90000, $defaultGroup['budgeted']['2027-01']);
		$this->assertSame(555500, $overrideGroup['budgeted']['2027-01']);

	}//end testAnnualBudgetIdOverrideSwapsTheBudgetedSeries()

	/**
	 * A `LedgerGroup` month where every resolved member's own kind was
	 * `actual` is itself reported as `kind: actual`, not the blanket
	 * `projected` `BudgetProjectionCalculator::groupProjected()` would
	 * report on its own (REQ-BCH-006's actual/projected split, extended to
	 * the group level by `groupMonthKind()`).
	 *
	 * @return void
	 */
	public function testGroupMonthIsActualWhenEveryMemberIsActual(): void {
		[$service] = $this->buildService($this->fixture());

		$result = $service->resolveSeries('adm-1', '2027-01', '2027-08');

		$group = $this->groupByKey($result, 'lg-1');
		// 2027-01 is within the single member's (1000) actual GL window —
		// the group's own trend for that month must read `actual`, not
		// `projected`, even though groupProjected() itself never returns
		// `actual`.
		$this->assertSame('actual', $group['trend']['2027-01']['kind']);
		$this->assertSame('projected', $group['trend']['2027-08']['kind']);

	}//end testGroupMonthIsActualWhenEveryMemberIsActual()

	/**
	 * A `LedgerGroup` with one projectable member and one unprojectable
	 * member is tagged `partial` on its own trend, not silently withheld or
	 * presented as complete — REQ-BPE-007 relayed through, not re-derived
	 * (the arithmetic itself stays `BudgetProjectionCalculator::groupProjected()`'s
	 * own, already-tested territory).
	 *
	 * @return void
	 */
	public function testGroupTrendCarriesPartialTagWhenAMemberIsUnprojectable(): void {
		$data = $this->fixture();
		// A second member (1050) with only one month of history — unprojectable.
		$data['Account'][] = ['accountNumber' => '1050', 'administrationId' => 'adm-1', 'accountType' => 'revenue'];
		$data['GLTransaction'][] = [
			'id' => 'tx-1050',
			'transactionNumber' => 'GL-1050',
			'administrationId' => 'adm-1',
			'state' => 'posted',
			'postingDate' => '2027-06-10',
		];
		$data['GLLine'][] = ['transactionId' => 'tx-1050', 'accountNumber' => '1050', 'side' => 'credit', 'amount' => 200.00];

		[$service] = $this->buildService($data);

		$result = $service->resolveSeries('adm-1', '2027-01', '2027-08');

		$group = $this->groupByKey($result, 'lg-1');
		$this->assertSame('projected', $group['trend']['2027-08']['kind']);
		$this->assertTrue($group['trend']['2027-08']['partial']);

	}//end testGroupTrendCarriesPartialTagWhenAMemberIsUnprojectable()

	/**
	 * Find one account envelope by its accountNumber.
	 *
	 * @param array<string,mixed> $result The `resolveSeries()` result.
	 * @param string $accountNumber The account number.
	 *
	 * @return array<string,mixed>
	 */
	private function accountByNumber(array $result, string $accountNumber): array {
		foreach ($result['accounts'] as $account) {
			if ($account['accountNumber'] === $accountNumber) {
				return $account;
			}
		}

		$this->fail('Account ' . $accountNumber . ' not found in result.');

	}//end accountByNumber()

	/**
	 * Find one LedgerGroup envelope by its key (id or slug).
	 *
	 * @param array<string,mixed> $result The `resolveSeries()` result.
	 * @param string $key The LedgerGroup id or slug.
	 *
	 * @return array<string,mixed>
	 */
	private function groupByKey(array $result, string $key): array {
		foreach ($result['ledgerGroups'] as $group) {
			if ($group['ledgerGroupKey'] === $key) {
				return $group;
			}
		}

		$this->fail('LedgerGroup ' . $key . ' not found in result.');

	}//end groupByKey()
}//end class
