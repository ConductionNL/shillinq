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
 * Every tax schema is protected by an authorization block OpenRegister enforces.
 */
final class TaxAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'BcfClaim' => ['BcfClaim'],
			'Belastingplichtige' => ['Belastingplichtige'],
			'BezwaarBeroep' => ['BezwaarBeroep'],
			'BtwAangifte' => ['BtwAangifte'],
			'CarryForwardLoss' => ['CarryForwardLoss'],
			'CbcrJurisdictionSummary' => ['CbcrJurisdictionSummary'],
			'CbcrReturn' => ['CbcrReturn'],
			'Deelneming' => ['Deelneming'],
			'DeferredTaxMovement' => ['DeferredTaxMovement'],
			'DefinitieveAanslag' => ['DefinitieveAanslag'],
			'EInvoiceMandate' => ['EInvoiceMandate'],
			'EnergielijstCode' => ['EnergielijstCode'],
			'EuVatRate' => ['EuVatRate'],
			'FiscaleCorrectie' => ['FiscaleCorrectie'],
			'FiscaleEenheid' => ['FiscaleEenheid'],
			'GlobeInformationReturn' => ['GlobeInformationReturn'],
			'GroupEntityRegistry' => ['GroupEntityRegistry'],
			'IBAangifte' => ['IBAangifte'],
			'IbAangifteExport' => ['IbAangifteExport'],
			'IBAuditTrail' => ['IBAuditTrail'],
			'IBBijtellingAuto' => ['IBBijtellingAuto'],
			'IBBox3Vermogen' => ['IBBox3Vermogen'],
			'IBExpenseAllocation' => ['IBExpenseAllocation'],
			'IBHeffingskortingenAlgemeen' => ['IBHeffingskortingenAlgemeen'],
			'IBLijfrenteAOV' => ['IBLijfrenteAOV'],
			'IBOndernemersaftrek' => ['IBOndernemersaftrek'],
			'IBProfitAttribution' => ['IBProfitAttribution'],
			'IBTaxParameterYear' => ['IBTaxParameterYear'],
			'IBWinstOpgave' => ['IBWinstOpgave'],
			'IcpOpgaaf' => ['IcpOpgaaf'],
			'IcpStatement' => ['IcpStatement'],
			'IcpSupply' => ['IcpSupply'],
			'Innovatiebox' => ['Innovatiebox'],
			'InnovatieboxAuditEvent' => ['InnovatieboxAuditEvent'],
			'InnovatieboxElection' => ['InnovatieboxElection'],
			'InnovatieboxTariff' => ['InnovatieboxTariff'],
			'InvesteringsAftrek' => ['InvesteringsAftrek'],
			'InvesteringsaftrekClaim' => ['InvesteringsaftrekClaim'],
			'InvestmentAsset' => ['InvestmentAsset'],
			'IossImport' => ['IossImport'],
			'KIATier' => ['KIATier'],
			'KORAnnualTurnover' => ['KORAnnualTurnover'],
			'KOREUTurnover' => ['KOREUTurnover'],
			'KorRegime' => ['KorRegime'],
			'KORRegistration' => ['KORRegistration'],
			'KORRevocation' => ['KORRevocation'],
			'KorThreshold' => ['KorThreshold'],
			'KORThresholdAlert' => ['KORThresholdAlert'],
			'MilieulijstCode' => ['MilieulijstCode'],
			'NexusCalculation' => ['NexusCalculation'],
			'OssPayment' => ['OssPayment'],
			'OssRegistration' => ['OssRegistration'],
			'OssReturn' => ['OssReturn'],
			'OssThresholdCounter' => ['OssThresholdCounter'],
			'PeriodicitySwitch' => ['PeriodicitySwitch'],
			'Pillar2JurisdictionComputation' => ['Pillar2JurisdictionComputation'],
			'Pillar2SafeHarbour' => ['Pillar2SafeHarbour'],
			'QdmttReturn' => ['QdmttReturn'],
			'QualifyingAsset' => ['QualifyingAsset'],
			'QuarterlyTaxStatement' => ['QuarterlyTaxStatement'],
			'SaftExportProfile' => ['SaftExportProfile'],
			'SaftFile' => ['SaftFile'],
			'TaxDeadline' => ['TaxDeadline'],
			'TaxEstimate' => ['TaxEstimate'],
			'TaxLossCarryForward' => ['TaxLossCarryForward'],
			'TaxPaymentTracking' => ['TaxPaymentTracking'],
			'TaxProvision' => ['TaxProvision'],
			'TaxRateReconciliation' => ['TaxRateReconciliation'],
			'TaxRegimeConfiguration' => ['TaxRegimeConfiguration'],
			'TaxSummaryReport' => ['TaxSummaryReport'],
			'TaxTreatyOverview' => ['TaxTreatyOverview'],
			'TemporaryDifference' => ['TemporaryDifference'],
			'TravelAgentMargin' => ['TravelAgentMargin'],
			'UrenAlert' => ['UrenAlert'],
			'UrenCategorie' => ['UrenCategorie'],
			'UrencriteriumYear' => ['UrencriteriumYear'],
			'UrenDagregistratie' => ['UrenDagregistratie'],
			'UrenEvidence' => ['UrenEvidence'],
			'UrenPrognose' => ['UrenPrognose'],
			'VamilDepreciation' => ['VamilDepreciation'],
			'VATAuditRecord' => ['VATAuditRecord'],
			'VatCorrection' => ['VatCorrection'],
			'VATDeclaration' => ['VATDeclaration'],
			'VATGLAccounts' => ['VATGLAccounts'],
			'VATLine' => ['VATLine'],
			'VatReturn' => ['VatReturn'],
			'VatReturnFiling' => ['VatReturnFiling'],
			'VatTariff' => ['VatTariff'],
			'ViesValidation' => ['ViesValidation'],
			'VoorlopigeAanslag' => ['VoorlopigeAanslag'],
			'Voorvoegingsverlies' => ['Voorvoegingsverlies'],
			'VpbAangifte' => ['VpbAangifte'],
			'VpbBalansLink' => ['VpbBalansLink'],
			'VpbTariefcatalogus' => ['VpbTariefcatalogus'],
			'WinstToerekening' => ['WinstToerekening'],
			'ZzpDeduction' => ['ZzpDeduction'],
			'ZzpDeductionAmounts' => ['ZzpDeductionAmounts'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
