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
 * Every audit, compliance and public-sector schema is protected by an authorization block OpenRegister enforces.
 */
final class ComplianceAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'Aansluiting' => ['Aansluiting'],
			'AansluitingResult' => ['AansluitingResult'],
			'ACMReport' => ['ACMReport'],
			'ActivityCostAllocation' => ['ActivityCostAllocation'],
			'AlertLog' => ['AlertLog'],
			'AlgemeenBelangBesluit' => ['AlgemeenBelangBesluit'],
			'AssuranceEngagement' => ['AssuranceEngagement'],
			'AuditDocument' => ['AuditDocument'],
			'AuditFinding' => ['AuditFinding'],
			'AuditFindingTemplate' => ['AuditFindingTemplate'],
			'AuditorStatement' => ['AuditorStatement'],
			'AuditSample' => ['AuditSample'],
			'AuditTrail' => ['AuditTrail'],
			'Bevinding' => ['Bevinding'],
			'CcmAuditCommitteeReport' => ['CcmAuditCommitteeReport'],
			'CcmBaseline' => ['CcmBaseline'],
			'CcmFinding' => ['CcmFinding'],
			'CcmRule' => ['CcmRule'],
			'CcmSegregationMatrix' => ['CcmSegregationMatrix'],
			'CcmUserFunctionAssignment' => ['CcmUserFunctionAssignment'],
			'CommercialActivity' => ['CommercialActivity'],
			'ComplianceAuditTrail' => ['ComplianceAuditTrail'],
			'ComplianceReport' => ['ComplianceReport'],
			'Controleprotocol' => ['Controleprotocol'],
			'DBAEvidenceDossier' => ['DBAEvidenceDossier'],
			'DBAIntake' => ['DBAIntake'],
			'DBAModelovereenkomst' => ['DBAModelovereenkomst'],
			'DBAOpdracht' => ['DBAOpdracht'],
			'DBAPortfolioRisico' => ['DBAPortfolioRisico'],
			'DBARisicoflag' => ['DBARisicoflag'],
			'DocumentRetention' => ['DocumentRetention'],
			'EligibilityRule' => ['EligibilityRule'],
			'EmissionSource' => ['EmissionSource'],
			'ENSIAJaarcyclus' => ['ENSIAJaarcyclus'],
			'EsrsAction' => ['EsrsAction'],
			'EsrsDataPoint' => ['EsrsDataPoint'],
			'EsrsPolicy' => ['EsrsPolicy'],
			'EsrsStatement' => ['EsrsStatement'],
			'EsrsTarget' => ['EsrsTarget'],
			'EuExpenditure' => ['EuExpenditure'],
			'EuProject' => ['EuProject'],
			'Evaluatievraag' => ['Evaluatievraag'],
			'GhgInventory' => ['GhgInventory'],
			'Grant' => ['Grant'],
			'IntegralCostPrice' => ['IntegralCostPrice'],
			'IroRecord' => ['IroRecord'],
			'IrregularityReport' => ['IrregularityReport'],
			'ManagementLetter' => ['ManagementLetter'],
			'MarketBenchmark' => ['MarketBenchmark'],
			'Materialiteit' => ['Materialiteit'],
			'MaterialityAssessment' => ['MaterialityAssessment'],
			'PublicSectorStatement' => ['PublicSectorStatement'],
			'Rechtmatigheidsbevinding' => ['Rechtmatigheidsbevinding'],
			'Rechtmatigheidsparagraaf' => ['Rechtmatigheidsparagraaf'],
			'Rechtmatigheidstoets' => ['Rechtmatigheidstoets'],
			'RetentionPolicy' => ['RetentionPolicy'],
			'RetentionRule' => ['RetentionRule'],
			'RetentionSchedule' => ['RetentionSchedule'],
			'SegregatedLedger' => ['SegregatedLedger'],
			'SiSaAssurance' => ['SiSaAssurance'],
			'SisaRegelingIndicator' => ['SisaRegelingIndicator'],
			'SisaReport' => ['SisaReport'],
			'Subsidie' => ['Subsidie'],
			'SubsidieVerantwoording' => ['SubsidieVerantwoording'],
			'SupportingDocument' => ['SupportingDocument'],
			'ToleranceMatrix' => ['ToleranceMatrix'],
			'Tolerantiegrens' => ['Tolerantiegrens'],
			'ValueChainActor' => ['ValueChainActor'],
			'VerklaringDraft' => ['VerklaringDraft'],
			'WMOAuditLog' => ['WMOAuditLog'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
