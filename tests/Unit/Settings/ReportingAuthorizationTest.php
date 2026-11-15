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
 * Every reporting, consolidation and IFRS schema is protected by an authorization block OpenRegister enforces.
 */
final class ReportingAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'AccountingFramework' => ['AccountingFramework'],
			'ActuarialValuation' => ['ActuarialValuation'],
			'AnnualReport' => ['AnnualReport'],
			'BalanceSheet' => ['BalanceSheet'],
			'BeleidsIndicator' => ['BeleidsIndicator'],
			'CashFlowItem' => ['CashFlowItem'],
			'CBSLine' => ['CBSLine'],
			'CBSSubmission' => ['CBSSubmission'],
			'ClaimsVoorzieningDetail' => ['ClaimsVoorzieningDetail'],
			'ConsolidatedBalance' => ['ConsolidatedBalance'],
			'ConsolidatedIncomeStatement' => ['ConsolidatedIncomeStatement'],
			'ConsolidatedReport' => ['ConsolidatedReport'],
			'ConsolidationGroup' => ['ConsolidationGroup'],
			'ConsolidationMapping' => ['ConsolidationMapping'],
			'ConsolidationPeriod' => ['ConsolidationPeriod'],
			'ContingentLiability' => ['ContingentLiability'],
			'ContractAsset' => ['ContractAsset'],
			'ContractCostAsset' => ['ContractCostAsset'],
			'ContractLiability' => ['ContractLiability'],
			'ContractModification' => ['ContractModification'],
			'CounterpartyBalance' => ['CounterpartyBalance'],
			'DebtPosition' => ['DebtPosition'],
			'DepreciationSchedule' => ['DepreciationSchedule'],
			'DirectorReport' => ['DirectorReport'],
			'DualTransaction' => ['DualTransaction'],
			'EliminationEntry' => ['EliminationEntry'],
			'EliminationJournal' => ['EliminationJournal'],
			'EliminationRule' => ['EliminationRule'],
			'EMUAdjustment' => ['EMUAdjustment'],
			'EMUReport' => ['EMUReport'],
			'FinancialStatement' => ['FinancialStatement'],
			'FixedAsset' => ['FixedAsset'],
			'FrameworkElection' => ['FrameworkElection'],
			'GarantievoorzieningDetail' => ['GarantievoorzieningDetail'],
			'GeneratedReport' => ['GeneratedReport'],
			'Goodwill' => ['Goodwill'],
			'GroupEntity' => ['GroupEntity'],
			'HerstructureringsvoorzieningDetail' => ['HerstructureringsvoorzieningDetail'],
			'IncomeStatement' => ['IncomeStatement'],
			'IntercompanyJournalEntry' => ['IntercompanyJournalEntry'],
			'IntercompanyMatch' => ['IntercompanyMatch'],
			'IntercompanyMismatch' => ['IntercompanyMismatch'],
			'IntercompanyRelation' => ['IntercompanyRelation'],
			'IntercompanyTransaction' => ['IntercompanyTransaction'],
			'IPAssetValuation' => ['IPAssetValuation'],
			'JubileumvoorzieningDetail' => ['JubileumvoorzieningDetail'],
			'LeaseContract' => ['LeaseContract'],
			'LeaseDisclosureTable' => ['LeaseDisclosureTable'],
			'LeasePaymentSchedule' => ['LeasePaymentSchedule'],
			'LeasePortfolioExemption' => ['LeasePortfolioExemption'],
			'LeaseReassessmentEvent' => ['LeaseReassessmentEvent'],
			'MaterieleVasteActiva' => ['MaterieleVasteActiva'],
			'MilieuvoorzieningDetail' => ['MilieuvoorzieningDetail'],
			'MinorityInterest' => ['MinorityInterest'],
			'Note' => ['Note'],
			'Paragraaf' => ['Paragraaf'],
			'PensioenvoorzieningDetail' => ['PensioenvoorzieningDetail'],
			'PensionAssetDetail' => ['PensionAssetDetail'],
			'PensionAssumptionSensitivity' => ['PensionAssumptionSensitivity'],
			'PensionDisclosureTabel' => ['PensionDisclosureTabel'],
			'PensionMovement' => ['PensionMovement'],
			'PensionPlan' => ['PensionPlan'],
			'PerformanceObligation' => ['PerformanceObligation'],
			'PriceAllocation' => ['PriceAllocation'],
			'Provision' => ['Provision'],
			'ProvisionDisclosureTabel' => ['ProvisionDisclosureTabel'],
			'ProvisionMovement' => ['ProvisionMovement'],
			'ReinvestmentReserve' => ['ReinvestmentReserve'],
			'ReportSchedule' => ['ReportSchedule'],
			'RevenueContract' => ['RevenueContract'],
			'RevenueRecognitionEvent' => ['RevenueRecognitionEvent'],
			'RevenueWaterfall' => ['RevenueWaterfall'],
			'ReviewWorkflow' => ['ReviewWorkflow'],
			'RJ270Stage' => ['RJ270Stage'],
			'SBRDocumentType' => ['SBRDocumentType'],
			'StandardSpecificCalculation' => ['StandardSpecificCalculation'],
			'ToleranceRule' => ['ToleranceRule'],
			'TransactionPrice' => ['TransactionPrice'],
			'TranslationAdjustment' => ['TranslationAdjustment'],
			'VariableConsiderationAdjustment' => ['VariableConsiderationAdjustment'],
			'XbrlInstance' => ['XbrlInstance'],
			'XBRLMapping' => ['XBRLMapping'],
			'XBRLTaxonomy' => ['XBRLTaxonomy'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
