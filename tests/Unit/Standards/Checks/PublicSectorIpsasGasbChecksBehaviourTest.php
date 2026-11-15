<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the measurement, recognition, lease and fund branches of the IPSAS
 * and GASB public-sector checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class PublicSectorIpsasGasbChecksBehaviourTest extends TestCase {

	/**
	 * Statements, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function cases(): array {
		$i = ['framework' => 'IPSAS'];
		$g = ['framework' => 'gasb'];
		// PV of 1000/yr at 5% over 3 years = 2723.25.
		$lease = ['annualPayment' => 1000, 'discountRate' => 0.05, 'termYears' => 3, 'leaseLiability' => 2723.25, 'rightOfUseAsset' => 2723.25];
		$short = ['shortTerm' => true, 'leaseLiability' => 0, 'rightOfUseAsset' => 0];
		$sbita = ['annualPayment' => 500, 'discountRate' => 0, 'termYears' => 4, 'subscriptionLiability' => 2000, 'rightOfUseAsset' => 2000, 'intangibleRouAsset' => true];
		$funds = ['pensionTrust', 'investmentTrust', 'privatePurposeTrust', 'custodial'];
		return [
			// Framework gate.
			'gasb statement skips ipsas rule' => ['ipsas-12-15-lower-cost-nrv', $g, true],
			'no framework does not skip' => ['ipsas-12-15-lower-cost-nrv', [], false],
			// IPSAS 12 — inventories.
			'nrv lower, carried at nrv' => ['ipsas-12-15-lower-cost-nrv', $i + ['inventories' => [['category' => 'Ordinary', 'cost' => 100, 'netRealizableValue' => 80, 'carryingAmount' => 80], ['category' => 'distribution', 'cost' => 1, 'carryingAmount' => 999]]], true],
			'nrv lower, carried at cost' => ['ipsas-12-15-lower-cost-nrv', $i + ['inventories' => [['category' => 'ordinary', 'cost' => 100, 'netRealizableValue' => 80, 'carryingAmount' => 100]]], false],
			'no ordinary inventories' => ['ipsas-12-15-lower-cost-nrv', $i + ['inventories' => [['category' => 'distribution', 'cost' => 1, 'carryingAmount' => 1]]], false],
			'inventory row not an array' => ['ipsas-12-15-lower-cost-nrv', $i + ['inventories' => ['x']], false],
			'distribution at replacement cost' => ['ipsas-12-17-distribution-replacement-cost', $i + ['inventories' => [['category' => 'distribution', 'cost' => 50, 'currentReplacementCost' => 40, 'carryingAmount' => 40]]], true],
			// IPSAS 19 — contingent liabilities.
			'contingent liability not recognised' => ['ipsas-19-35-no-contingent-liability', $i + ['contingentLiabilities' => [['recognized' => false], ['recognized' => '0']]], true],
			'contingent liability recognised' => ['ipsas-19-35-no-contingent-liability', $i + ['contingentLiabilities' => [['recognized' => false], ['recognized' => true]]], false],
			'contingent liability flag missing' => ['ipsas-19-35-no-contingent-liability', $i + ['contingentLiabilities' => [[]]], false],
			'no contingent liabilities listed' => ['ipsas-19-35-no-contingent-liability', $i + ['contingentLiabilities' => []], false],
			// IPSAS 21 — impairment.
			'impairment measured' => ['ipsas-21-52-recognise-impairment', $i + ['impairments' => [['carryingAmountBefore' => 100, 'recoverableServiceAmount' => 70, 'carryingAmount' => 70, 'impairmentLoss' => 30]]], true],
			'impairment carrying amount off' => ['ipsas-21-52-recognise-impairment', $i + ['impairments' => [['carryingAmountBefore' => 100, 'recoverableServiceAmount' => 70, 'carryingAmount' => 75, 'impairmentLoss' => 30]]], false],
			'impairment loss off' => ['ipsas-21-52-recognise-impairment', $i + ['impairments' => [['carryingAmountBefore' => 100, 'recoverableServiceAmount' => 70, 'carryingAmount' => 70, 'impairmentLoss' => 25]]], false],
			'impairment none listed' => ['ipsas-21-52-recognise-impairment', $i, false],
			'loss in surplus or deficit' => ['ipsas-21-54-loss-in-surplus-deficit', $i + ['impairments' => [['recognizedIn' => 'SurplusOrDeficit'], ['revalued' => true, 'recognizedIn' => 'revaluationSurplus']]], true],
			'loss in equity without revaluation' => ['ipsas-21-54-loss-in-surplus-deficit', $i + ['impairments' => [['recognizedIn' => 'equity']]], false],
			'revalued loss without destination' => ['ipsas-21-54-loss-in-surplus-deficit', $i + ['impairments' => [['revalued' => true]]], false],
			'loss destination none listed' => ['ipsas-21-54-loss-in-surplus-deficit', $i, false],
			// IPSAS 23 — non-exchange revenue.
			'condition creates liability' => ['ipsas-23-44-condition-creates-liability', $i + ['nonExchangeRevenues' => [['amount' => 100, 'liabilityRecognized' => 60, 'revenueRecognized' => 40, 'hasCondition' => true], ['amount' => 50, 'revenueRecognized' => 50, 'hasCondition' => false]]], true],
			'condition without liability' => ['ipsas-23-44-condition-creates-liability', $i + ['nonExchangeRevenues' => [['amount' => 100, 'liabilityRecognized' => 0, 'revenueRecognized' => 100, 'hasCondition' => true]]], false],
			'liability without condition' => ['ipsas-23-44-condition-creates-liability', $i + ['nonExchangeRevenues' => [['amount' => 100, 'liabilityRecognized' => 10, 'revenueRecognized' => 90]]], false],
			'split does not add up' => ['ipsas-23-44-condition-creates-liability', $i + ['nonExchangeRevenues' => [['amount' => 100, 'liabilityRecognized' => 60, 'revenueRecognized' => 30, 'hasCondition' => true]]], false],
			'non-exchange none listed' => ['ipsas-23-44-condition-creates-liability', $i, false],
			// IPSAS 23 / 47 — taxes.
			'tax asset without taxable event' => ['ipsas-23-59-tax-on-taxable-event', $i + ['taxAssets' => [['taxableEventOccurred' => true], ['taxableEventOccurred' => false]]], false],
			'tax assets none listed' => ['ipsas-47-36-taxes-on-taxable-event', $i, false],
			// IPSAS 24 — budget comparison.
			'budget comparison complete' => ['ipsas-24-14-budget-comparison', $i + ['budgetComparison' => ['originalBudget' => 1, 'finalBudget' => 2, 'actualAmount' => 3, 'basis' => ' Comparable ', 'materialDifferenceNote' => 'Explained']], true],
			'budget comparison no note' => ['ipsas-24-14-budget-comparison', $i + ['budgetComparison' => ['originalBudget' => 1, 'finalBudget' => 2, 'actualAmount' => 3, 'basis' => 'comparable', 'materialDifferenceNote' => ' ']], false],
			'budget comparison different basis' => ['ipsas-24-14-budget-comparison', $i + ['budgetComparison' => ['originalBudget' => 1, 'finalBudget' => 2, 'actualAmount' => 3, 'basis' => 'cash', 'materialDifferenceNote' => 'x']], false],
			'budget comparison no actuals' => ['ipsas-24-14-budget-comparison', $i + ['budgetComparison' => ['originalBudget' => 1, 'finalBudget' => 2]], false],
			'budget comparison missing' => ['ipsas-24-14-budget-comparison', $i, false],
			// IPSAS 35 — intragroup eliminations.
			'not consolidated' => ['ipsas-35-40-eliminate-intragroup', $i + ['consolidated' => false], true],
			'all eliminations' => ['ipsas-35-40-eliminate-intragroup', $i + ['consolidated' => true, 'intragroupEliminations' => [['type' => 'Investment'], ['type' => 'intragroupBalance'], ['type' => 'intragroupTransaction']]], true],
			'transaction elimination missing' => ['ipsas-35-40-eliminate-intragroup', $i + ['consolidated' => true, 'intragroupEliminations' => [['type' => 'investment'], ['type' => 'intragroupBalance']]], false],
			'consolidated without eliminations' => ['ipsas-35-40-eliminate-intragroup', $i + ['consolidated' => '1'], false],
			// IPSAS 39 — short-term benefits.
			'benefits undiscounted' => ['ipsas-39-11-short-term-benefits', $i + ['shortTermEmployeeBenefits' => [['discounted' => false, 'serviceRendered' => true, 'undiscountedAmount' => 10, 'liabilityRecognized' => 10, 'expenseRecognized' => 10]]], true],
			'benefits discounted' => ['ipsas-39-11-short-term-benefits', $i + ['shortTermEmployeeBenefits' => [['discounted' => true, 'serviceRendered' => true, 'undiscountedAmount' => 10, 'liabilityRecognized' => 10, 'expenseRecognized' => 10]]], false],
			'benefits expense off' => ['ipsas-39-11-short-term-benefits', $i + ['shortTermEmployeeBenefits' => [['discounted' => false, 'serviceRendered' => true, 'undiscountedAmount' => 10, 'liabilityRecognized' => 10, 'expenseRecognized' => 9]]], false],
			'benefits none listed' => ['ipsas-39-11-short-term-benefits', $i, false],
			// IPSAS 41 — financial instruments.
			'party and recognised' => ['ipsas-41-10-recognise-on-becoming-party', $i + ['financialInstruments' => [['partyToContract' => true, 'recognized' => 1], ['partyToContract' => false, 'recognized' => 'false']]], true],
			'party but not recognised' => ['ipsas-41-10-recognise-on-becoming-party', $i + ['financialInstruments' => [['partyToContract' => true, 'recognized' => false]]], false],
			'party flag missing' => ['ipsas-41-10-recognise-on-becoming-party', $i + ['financialInstruments' => [['recognized' => true]]], false],
			'instruments none listed' => ['ipsas-41-10-recognise-on-becoming-party', $i, false],
			// IPSAS 43 — leases.
			'lease has rou and liability' => ['ipsas-43-23-right-of-use-model', $i + ['leases' => [$lease, $short]], true],
			'lease without rou' => ['ipsas-43-23-right-of-use-model', $i + ['leases' => [['rightOfUseAsset' => 0] + $lease]], false],
			'leases none listed' => ['ipsas-43-23-right-of-use-model', $i, false],
			'lease at present value' => ['ipsas-43-27-lease-liability-pv', $i + ['leases' => [$lease, ['maxTermMonths' => 12]]], true],
			'lease off present value' => ['ipsas-43-27-lease-liability-pv', $i + ['leases' => [['leaseLiability' => 3000] + $lease]], false],
			'lease without payment' => ['ipsas-43-27-lease-liability-pv', $i + ['leases' => [['annualPayment' => 0] + $lease]], false],
			'lease pv none listed' => ['ipsas-43-27-lease-liability-pv', $i, false],
			// GASB 34 — funds.
			'ipsas statement skips gasb rule' => ['gasb-34-major-fund-criteria', $i, true],
			'major funds consistent' => ['gasb-34-major-fund-criteria', $g + ['funds' => [['isGeneralFund' => true, 'major' => true], ['categoryPercent' => 10, 'combinedPercent' => 5, 'major' => true], ['categoryPercent' => 9.9, 'combinedPercent' => 50, 'major' => false]]], true],
			'general fund not major' => ['gasb-34-major-fund-criteria', $g + ['funds' => [['isGeneralFund' => true, 'major' => false]]], false],
			'qualifying fund not major' => ['gasb-34-major-fund-criteria', $g + ['funds' => [['categoryPercent' => 10, 'combinedPercent' => 5, 'major' => false]]], false],
			'non-qualifying fund flagged major' => ['gasb-34-major-fund-criteria', $g + ['funds' => [['categoryPercent' => 10, 'combinedPercent' => 4.9, 'major' => true]]], false],
			'funds none listed' => ['gasb-34-major-fund-criteria', $g, false],
			// GASB 84 — fiduciary fund types.
			'all four fiduciary types' => ['gasb-84-four-fund-types', $g + ['fiduciaryFunds' => array_map(static fn (string $t): array => ['fundType' => strtoupper($t)], $funds)], true],
			'fiduciary type missing' => ['gasb-84-four-fund-types', $g + ['fiduciaryFunds' => [['fundType' => 'pensionTrust'], ['fundType' => 'custodial']]], false],
			'unknown fiduciary type' => ['gasb-84-four-fund-types', $g + ['fiduciaryFunds' => [['fundType' => 'agency']]], false],
			'fiduciary none listed' => ['gasb-84-four-fund-types', $g, false],
			// GASB 87 / 96 — leases and subscriptions.
			'single lease model' => ['gasb-87-single-lease-model', $g + ['leases' => [['singleModel' => true]]], true],
			'dual lease model' => ['gasb-87-single-lease-model', $g + ['leases' => [['singleModel' => true], ['singleModel' => false]]], false],
			'single model none listed' => ['gasb-87-single-lease-model', $g, false],
			'lessee recognition at pv' => ['gasb-87-lessee-recognition', $g + ['leases' => [$lease + ['intangibleRouAsset' => true], $short]], true],
			'lessee rou not intangible' => ['gasb-87-lessee-recognition', $g + ['leases' => [$lease]], false],
			'lessee off pv' => ['gasb-87-lessee-recognition', $g + ['leases' => [['leaseLiability' => 100, 'intangibleRouAsset' => true] + $lease]], false],
			'lessee none listed' => ['gasb-87-lessee-recognition', $g, false],
			'sbita at undiscounted total' => ['gasb-96-sbita-recognition', $g + ['sbitas' => [$sbita]], true],
			'sbita without rou' => ['gasb-96-sbita-recognition', $g + ['sbitas' => [['rightOfUseAsset' => 0] + $sbita]], false],
			'short-term lease kept off balance' => ['gasb-87-short-term-exception', $g + ['leases' => [$lease, $short]], true],
			'short-term lease capitalised' => ['gasb-87-short-term-exception', $g + ['leases' => [['leaseLiability' => 10] + $short]], false],
			'short-term none listed' => ['gasb-87-short-term-exception', $g, false],
			'short-term sbita capitalised' => ['gasb-96-short-term-exception', $g + ['sbitas' => [['maxTermMonths' => 6, 'subscriptionLiability' => 0, 'rightOfUseAsset' => 5]]], false],
			// GASB 103 — budgetary comparison.
			'budgetary comparison as rsi' => ['gasb-103-budgetary-comparison-rsi', $g + ['budgetaryComparison' => ['varianceColumns' => ['a', 'b'], 'presentedAsRsi' => true, 'significantVarianceNote' => 'x']], true],
			'budgetary comparison one column' => ['gasb-103-budgetary-comparison-rsi', $g + ['budgetaryComparison' => ['varianceColumns' => ['a'], 'presentedAsRsi' => true, 'significantVarianceNote' => 'x']], false],
			'budgetary comparison no note' => ['gasb-103-budgetary-comparison-rsi', $g + ['budgetaryComparison' => ['varianceColumns' => ['a', 'b'], 'presentedAsRsi' => true]], false],
			'budgetary comparison missing' => ['gasb-103-budgetary-comparison-rsi', $g, false],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $ruleId, array $object, bool $expected): void {
		$check = PublicSectorIpsasGasbChecks::checks()['PublicSectorStatement'][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'US']));

	}//end testVerdict()
}//end class
