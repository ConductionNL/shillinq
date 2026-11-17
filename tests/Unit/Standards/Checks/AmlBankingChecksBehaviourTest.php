<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\AmlBankingChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the threshold, deadline, screening and reconciliation branches of
 * the AML, cash-limit and banking checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\AmlBankingChecks
 * @uses \OCA\Shillinq\Standards\Checks\CheckFields
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class AmlBankingChecksBehaviourTest extends TestCase {

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$gl = 'GLTransaction';
		$cash = 'CashTransaction';
		$rep = 'AmlReport';
		$scr = 'SanctionsScreening';
		$pro = ['paymentMethod' => 'cash', 'partyCapacity' => 'business'];
		$ctr = ['reportType' => 'CTR'];
		$f8300 = ['reportType' => '8300'];
		return [
			// Electronic payment records.
			'electronic, complete record' => [$gl, 'ledger-electronic-payment-records', ['paymentMethod' => ' SEPA ', 'paymentReference' => 'E2E-1', 'counterparty' => 'ACME', 'valueDate' => '2026-01-02'], true],
			'electronic, no counterparty' => [$gl, 'ledger-electronic-payment-records', ['paymentMethod' => 'ideal', 'paymentReference' => 'E2E-1', 'valueDate' => '2026-01-02'], false],
			'electronic, no value date' => [$gl, 'ledger-electronic-payment-records', ['paymentMethod' => 'card', 'paymentReference' => 'E2E-1', 'counterparty' => 'ACME'], false],
			'non-electronic skips' => [$gl, 'ledger-electronic-payment-records', ['paymentMethod' => 'cash'], true],
			// Closed periods.
			'draft skips' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'draft', 'periodState' => 'closed'], true],
			'posted to closed period' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'Posted', 'periodState' => ' Closed '], false],
			'reversed into reopened period' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'reversed', 'periodState' => 'closed', 'reopened' => true], true],
			'posted to locked period' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'posted', 'periodLocked' => true], false],
			'posted to locked, reopened period' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'posted', 'periodLocked' => true, 'reopened' => true], true],
			'posted to open period' => [$gl, 'ledger-no-post-to-closed-period', ['state' => 'posted', 'periodState' => 'open', 'periodLocked' => true], true],
			// Trust account segregation.
			'not a trust account' => ['Account', 'nl-derdengelden-segregation', ['accountType' => 'bank'], true],
			'derdengelden segregated' => ['Account', 'nl-derdengelden-segregation', ['isDerdengeldenAccount' => true, 'segregated' => true], true],
			'escrow commingled' => ['Account', 'us-iolta-escrow-segregation', ['isEscrowAccount' => true, 'segregated' => true, 'commingled' => true], false],
			'iolta type not segregated' => ['Account', 'us-iolta-escrow-segregation', ['accountType' => ' IOLTA '], false],
			// Cash limits.
			'eu limit, non-cash skips' => [$cash, 'eu-cash-limit-10000', ['paymentMethod' => 'card', 'amount' => 50000], true],
			'eu limit, private to private skips' => [$cash, 'eu-cash-limit-10000', ['partyCapacity' => 'private', 'amount' => 50000], true],
			'eu limit, under' => [$cash, 'eu-cash-limit-10000', $pro + ['amount' => 9999.99], true],
			'eu limit, at limit' => [$cash, 'eu-cash-limit-10000', $pro + ['amount' => 10000], false],
			'eu limit, linked total counts' => [$cash, 'eu-cash-limit-10000', $pro + ['amount' => 4000, 'linkedTransactionsTotal' => 12000], false],
			'eu private exemption, private to business' => [$cash, 'eu-cash-limit-private-exemption', ['partyCapacity' => 'private', 'counterpartyType' => 'business', 'amount' => 20000], false],
			'eu private exemption, private to consumer' => [$cash, 'eu-cash-limit-private-exemption', ['partyCapacity' => 'private', 'counterpartyType' => 'consumer', 'amount' => 20000], true],
			'member state limit unset' => [$cash, 'eu-cash-limit-member-state-lower', [], true],
			'member state limit lower' => [$cash, 'eu-cash-limit-member-state-lower', ['appliedCashLimit' => '3000'], true],
			'member state limit above ceiling' => [$cash, 'eu-cash-limit-member-state-lower', ['appliedCashLimit' => 15000], false],
			'fr limit, over' => [$cash, 'fr-cash-limit-1000', $pro + ['amount' => 1000], false],
			'it limit, non-cash skips' => [$cash, 'it-cash-limit-5000', ['paymentMethod' => 'card', 'amount' => 9000], true],
			'it limit, under' => [$cash, 'it-cash-limit-5000', $pro + ['amount' => 4999], true],
			'it limit, over' => [$cash, 'it-cash-limit-5000', $pro + ['amount' => 5000], false],
			'es limit, over' => [$cash, 'es-cash-limit-1000', $pro + ['amount' => 1500], false],
			// CDD on cash.
			'occasional cdd, under threshold' => [$cash, 'eu-cash-large-occasional-cdd', ['amount' => 2999], true],
			'occasional cdd, at threshold without cdd' => [$cash, 'eu-cash-large-occasional-cdd', ['amount' => 3000], false],
			'dealer cdd performed' => [$cash, 'de-cash-dealer-cdd-10000', ['amount' => 10000, 'cddPerformed' => true], true],
			'precious metals, other goods' => [$cash, 'de-precious-metals-cash-2000', ['goodsCategory' => 'cars', 'amount' => 5000], true],
			'precious metals, under threshold' => [$cash, 'de-precious-metals-cash-2000', ['goodsCategory' => 'Precious-Metals', 'amount' => 1999], true],
			'precious metals, over without cdd' => [$cash, 'de-precious-metals-cash-2000', ['goodsCategory' => 'precious-metals', 'amount' => 2000], false],
			'precious metals, over with cdd' => [$cash, 'de-precious-metals-cash-2000', ['goodsCategory' => 'precious-metals', 'amount' => 2000, 'cddPerformed' => true], true],
			// Form 8300 on cash.
			'8300 at threshold skips' => [$cash, 'us-form8300-cash', ['amount' => 10000], true],
			'8300 over, not flagged' => [$cash, 'us-form8300-cash', ['amount' => 10000.01], false],
			'8300 over, flagged' => [$cash, 'us-form8300-cash', ['amount' => 12000, 'form8300Required' => true], true],
			'8300 aggregate over, not flagged' => [$cash, 'us-form8300-related-transactions', ['amount' => 100, 'aggregate12mTotal' => 10500], false],
			'8300 aggregate over, flagged' => [$cash, 'us-form8300-related-transactions', ['aggregate12mTotal' => 10500, 'form8300Required' => true], true],
			'8300 aggregate falls back to amount' => [$cash, 'us-form8300-related-transactions', ['amount' => 9000], true],
			// Reports.
			'ctr other type skips' => [$rep, 'us-ctr-10000', ['reportType' => 'SAR', 'amount' => 1], true],
			'ctr over threshold' => [$rep, 'us-ctr-10000', $ctr + ['amount' => 10000.01], true],
			'ctr at threshold' => [$rep, 'us-ctr-10000', $ctr + ['amount' => 10000], false],
			'ctr efiled in time' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => '2026-03-01', 'filedDate' => '2026-03-16', 'eFiled' => true], true],
			'ctr filed in time on paper' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => '2026-03-01', 'filedDate' => '2026-03-16'], false],
			'ctr filed late' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => '2026-03-01', 'filedDate' => '2026-03-17', 'eFiled' => true], false],
			'ctr filed before transaction' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => '2026-03-01', 'filedDate' => '2026-02-28', 'eFiled' => true], false],
			'ctr no filed date' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => '2026-03-01', 'eFiled' => true], false],
			'ctr unparsable transaction date' => [$rep, 'us-ctr-15-day-efile', $ctr + ['transactionDate' => 'not a date', 'filedDate' => '2026-03-02', 'eFiled' => true], false],
			'ctr same-day aggregate over' => [$rep, 'us-ctr-aggregate-same-day', $ctr + ['amount' => 1, 'sameDayAggregate' => 10001], true],
			'ctr same-day aggregate under' => [$rep, 'us-ctr-aggregate-same-day', $ctr + ['amount' => 20000, 'sameDayAggregate' => 9000], false],
			'sar filed within 30 days' => [$rep, 'us-sar-30-day-filing', ['reportType' => 'sar', 'detectionDate' => '2026-01-01', 'filedDate' => '2026-01-31'], true],
			'sar filed late' => [$rep, 'us-sar-30-day-filing', ['reportType' => 'sar', 'detectionDate' => '2026-01-01', 'filedDate' => '2026-02-01'], false],
			'8300 filed late' => [$rep, 'us-form8300-15-day-filing', $f8300 + ['transactionDate' => '2026-01-01', 'filedDate' => '2026-01-17'], false],
			'payer statement other type skips' => [$rep, 'us-form8300-payer-statement', $ctr, true],
			'payer statement by Jan 31' => [$rep, 'us-form8300-payer-statement', $f8300 + ['transactionDate' => '2026-12-20', 'payerStatementDate' => '2027-01-31'], true],
			'payer statement after Jan 31' => [$rep, 'us-form8300-payer-statement', $f8300 + ['transactionDate' => '2026-12-20', 'payerStatementDate' => '2027-02-01'], false],
			'payer statement missing' => [$rep, 'us-form8300-payer-statement', $f8300 + ['transactionDate' => '2026-12-20'], false],
			'cmir at threshold' => [$rep, 'us-cmir-10000', ['reportType' => 'cmir', 'amount' => 10000], false],
			'fbar at threshold' => [$rep, 'us-fbar-foreign-accounts', ['reportType' => 'fbar', 'amount' => 10000], true],
			'fbar under threshold' => [$rep, 'us-fbar-foreign-accounts', ['reportType' => 'fbar', 'amount' => 9999], false],
			'unusual report filed' => [$rep, 'nl-cash-no-general-limit', ['reportType' => 'unusual', 'amount' => 10000, 'filedDate' => '2026-01-01'], true],
			'unusual report not filed' => [$rep, 'nl-cash-no-general-limit', ['reportType' => 'unusual', 'amount' => 10000], false],
			'unusual report under threshold' => [$rep, 'nl-cash-no-general-limit', ['reportType' => 'unusual', 'amount' => 9000, 'filedDate' => '2026-01-01'], false],
			'report retained five years' => [$rep, 'nl-wwft-report-retention-5yr', ['filedDate' => '2026-01-01', 'retentionUntil' => '2031-01-01'], true],
			'report retained too short' => [$rep, 'nl-wwft-report-retention-5yr', ['filedDate' => '2026-01-01', 'retentionUntil' => '2030-12-31'], false],
			'report retention missing' => [$rep, 'nl-wwft-report-retention-5yr', ['filedDate' => '2026-01-01'], false],
			// Sanctions screening.
			'screened before transaction' => [$scr, 'eu-amlr-targeted-sanctions-screening', ['screenedDate' => '2026-01-01', 'transactionDate' => '2026-01-01'], true],
			'screened after transaction' => [$scr, 'eu-amlr-targeted-sanctions-screening', ['screenedDate' => '2026-01-02', 'transactionDate' => '2026-01-01'], false],
			'screened, no transaction yet' => [$scr, 'eu-amlr-targeted-sanctions-screening', ['screenedDate' => '2026-01-02'], true],
			'never screened' => [$scr, 'eu-amlr-targeted-sanctions-screening', [], false],
			'sanctiewet match reported' => [$scr, 'nl-wwft-sanctions-sanctiewet', ['screenedDate' => '2026-01-01', 'matched' => true, 'reportedDate' => '2026-01-02'], true],
			'sanctiewet match unreported' => [$scr, 'nl-wwft-sanctions-sanctiewet', ['screenedDate' => '2026-01-01', 'matched' => true], false],
			'sanctiewet no match' => [$scr, 'nl-wwft-sanctions-sanctiewet', ['screenedDate' => '2026-01-01'], true],
			'ofac via list name' => [$scr, 'us-ofac-sanctions-screening', ['listName' => 'ofac sdn'], true],
			'ofac via lists screened' => [$scr, 'us-ofac-sanctions-screening', ['listsScreened' => ['EU', 'sdn list']], true],
			'ofac not among lists' => [$scr, 'us-ofac-sanctions-screening', ['listsScreened' => ['EU', 'UN']], false],
			'ofac lists not an array' => [$scr, 'us-ofac-sanctions-screening', ['listsScreened' => 'OFAC'], false],
			'ofac match unreported' => [$scr, 'us-ofac-sanctions-screening', ['listName' => 'OFAC', 'matched' => true], false],
			'ofac match reported' => [$scr, 'us-ofac-sanctions-screening', ['listName' => 'OFAC', 'matched' => true, 'reportedDate' => '2026-01-01'], true],
			// CDD records.
			'cdd under occasional threshold' => ['CddRecord', 'eu-amlr-cdd-occasional-15000', ['transactionAmount' => 14999], true],
			'cdd over threshold not performed' => ['CddRecord', 'eu-amlr-cdd-occasional-15000', ['transactionAmount' => 15000], false],
			'ubo under threshold' => ['CddRecord', 'eu-amlr-ubo-25-percent', ['uboOwnershipPct' => '24.9'], true],
			'ubo at threshold unidentified' => ['CddRecord', 'eu-amlr-ubo-25-percent', ['uboOwnershipPct' => 25], false],
			'ubo at threshold identified' => ['CddRecord', 'eu-amlr-ubo-25-percent', ['uboOwnershipPct' => 25, 'uboIdentified' => true], true],
			'ubo pct not numeric' => ['CddRecord', 'eu-amlr-ubo-25-percent', ['uboOwnershipPct' => 'a lot', 'uboIdentified' => true], false],
			// Travel rule.
			'travel rule missing beneficiary account' => ['FundsTransfer', 'eu-tfr-travel-rule', ['originatorName' => 'A', 'originatorAccount' => 'NL01', 'beneficiaryName' => 'B'], false],
			// Escrow.
			'escrow balances all positive' => ['TrustLedger', 'ledger-escrow-no-overdraw', ['clientBalances' => [['balance' => 0], ['balance' => '10.5']]], true],
			'escrow one client overdrawn' => ['TrustLedger', 'ledger-escrow-no-overdraw', ['clientBalances' => [['balance' => 10], ['balance' => -0.01]]], false],
			'escrow non-numeric balance' => ['TrustLedger', 'ledger-escrow-no-overdraw', ['clientBalances' => [['balance' => 'n/a']]], false],
			'escrow entry not an array' => ['TrustLedger', 'ledger-escrow-no-overdraw', ['clientBalances' => [5]], false],
			'escrow no balances' => ['TrustLedger', 'ledger-escrow-no-overdraw', ['clientBalances' => []], false],
			// Cash counts.
			'cash count matches' => ['CashCount', 'ledger-cash-count-kasadministratie', ['countedAmount' => '100.00', 'bookBalance' => 100], true],
			'cash count differs' => ['CashCount', 'ledger-cash-count-kasadministratie', ['countedAmount' => 100.01, 'bookBalance' => 100], false],
			'cash count no book balance' => ['CashCount', 'ledger-cash-count-kasadministratie', ['countedAmount' => 100, 'bookBalance' => ''], false],
			'cash balance negative' => ['CashCount', 'ledger-cash-balance-non-negative', ['runningBalance' => -0.01], false],
			'cash balance missing' => ['CashCount', 'ledger-cash-balance-non-negative', [], false],
			'z report differs' => ['CashCount', 'ledger-daily-z-report', ['zReportTotal' => 50, 'recordedTakings' => 49.99], false],
			'z report missing takings' => ['CashCount', 'ledger-daily-z-report', ['zReportTotal' => 50], false],
			// Bank reconciliation.
			'bank reconciled' => ['BankReconciliation', 'ledger-bank-reconciliation', ['closingBalance' => 1000, 'reconciledBalance' => '1000.00'], true],
			'bank difference documented' => ['BankReconciliation', 'ledger-bank-reconciliation', ['closingBalance' => 1000, 'reconciledBalance' => 990, 'variance' => 10, 'varianceReason' => 'Bank fee'], true],
			'bank difference without reason' => ['BankReconciliation', 'ledger-bank-reconciliation', ['closingBalance' => 1000, 'reconciledBalance' => 990, 'variance' => 10], false],
			'bank difference without variance' => ['BankReconciliation', 'ledger-bank-reconciliation', ['closingBalance' => 1000, 'reconciledBalance' => 990, 'varianceReason' => 'Bank fee'], false],
			'bank no closing balance' => ['BankReconciliation', 'ledger-bank-reconciliation', ['reconciledBalance' => 990], false],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $type     The object type.
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The object checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $type, string $ruleId, array $object, bool $expected): void {
		$check = AmlBankingChecks::checks()[$type][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()
}//end class
