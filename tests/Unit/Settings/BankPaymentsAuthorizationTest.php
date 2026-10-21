<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every bank, payment and expense schema is protected by an authorization block OpenRegister enforces.
 */
final class BankPaymentsAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'AmlReport' => ['AmlReport'],
			'APTransaction' => ['APTransaction'],
			'BankAccount' => ['BankAccount'],
			'BankConnection' => ['BankConnection'],
			'BankingRule' => ['BankingRule'],
			'BankReconciliation' => ['BankReconciliation'],
			'BankReconciliationGroup' => ['BankReconciliationGroup'],
			'BankReconciliationMatch' => ['BankReconciliationMatch'],
			'BankStatement' => ['BankStatement'],
			'BankStatementLine' => ['BankStatementLine'],
			'CashCount' => ['CashCount'],
			'CashflowAPSchedule' => ['CashflowAPSchedule'],
			'CashflowARProjection' => ['CashflowARProjection'],
			'CashflowBufferPolicy' => ['CashflowBufferPolicy'],
			'CashflowCalibrationReport' => ['CashflowCalibrationReport'],
			'CashflowForecastHorizon' => ['CashflowForecastHorizon'],
			'CashflowRecurring' => ['CashflowRecurring'],
			'CashflowScenario' => ['CashflowScenario'],
			'CashFlowStatement' => ['CashFlowStatement'],
			'CashflowWeek' => ['CashflowWeek'],
			'CashForecast' => ['CashForecast'],
			'CashPool' => ['CashPool'],
			'CashPoolMembership' => ['CashPoolMembership'],
			'CashTransaction' => ['CashTransaction'],
			'CddRecord' => ['CddRecord'],
			'CurrencyBalance' => ['CurrencyBalance'],
			'Derivaat' => ['Derivaat'],
			'DirectDebitBatch' => ['DirectDebitBatch'],
			'DirectDebitCollection' => ['DirectDebitCollection'],
			'DunningNotice' => ['DunningNotice'],
			'ExpenseClaimEntry' => ['ExpenseClaimEntry'],
			'FundsTransfer' => ['FundsTransfer'],
			'FXContract' => ['FXContract'],
			'FXPosition' => ['FXPosition'],
			'FxRate' => ['FxRate'],
			'FxRevaluationPosting' => ['FxRevaluationPosting'],
			'IntercompanyLoan' => ['IntercompanyLoan'],
			'KasgeldLimiet' => ['KasgeldLimiet'],
			'Lening' => ['Lening'],
			'LiquidityKPI' => ['LiquidityKPI'],
			'MileageEntry' => ['MileageEntry'],
			'MileageRate' => ['MileageRate'],
			'PassThroughMarkupRule' => ['PassThroughMarkupRule'],
			'Payee' => ['Payee'],
			'Payment' => ['Payment'],
			'PaymentRun' => ['PaymentRun'],
			'PerDiem' => ['PerDiem'],
			'PerDiemRate' => ['PerDiemRate'],
			'PreNotification' => ['PreNotification'],
			'ProviderPayout' => ['ProviderPayout'],
			'QuartaalrapportageFido' => ['QuartaalrapportageFido'],
			'RealisedFxPosting' => ['RealisedFxPosting'],
			'ReconciliationBridge' => ['ReconciliationBridge'],
			'ReconciliationMatch' => ['ReconciliationMatch'],
			'ReconciliationReport' => ['ReconciliationReport'],
			'ReimbursementPolicy' => ['ReimbursementPolicy'],
			'RenteRisicoNorm' => ['RenteRisicoNorm'],
			'RepaymentInstallment' => ['RepaymentInstallment'],
			'RTransaction' => ['RTransaction'],
			'SanctionsScreening' => ['SanctionsScreening'],
			'SchatkistbankierenSaldo' => ['SchatkistbankierenSaldo'],
			'SchatkistPosition' => ['SchatkistPosition'],
			'SepaMandate' => ['SepaMandate'],
			'TreasuryAccount' => ['TreasuryAccount'],
			'TreasuryParagraaf' => ['TreasuryParagraaf'],
			'Treasurystatuut' => ['Treasurystatuut'],
			'TrustLedger' => ['TrustLedger'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
