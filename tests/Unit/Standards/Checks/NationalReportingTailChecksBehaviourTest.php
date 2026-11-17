<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\NationalReportingTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the composition, size-class, retention and filing branches of the
 * Dutch, German, French and ESEF reporting checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalReportingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalReportingTailChecksBehaviourTest extends TestCase {

	/**
	 * Financial statements, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function cases(): array {
		$fixed = ['fixedAssetClasses' => ['intangible' => 10, 'tangible' => '20', 'financial' => 30]];
		$maturity = ['shortTerm' => 40, 'longTerm' => 60, 'overFiveYears' => 10];
		$fec = ['journalCode' => 'VE', 'entryNum' => '1', 'ecritureDate' => '20260101', 'compteNum' => '411000', 'pieceRef' => 'F1', 'debit' => 100, 'credit' => 0];
		$bs = ['fixedAssets' => 1, 'currentAssets' => 2, 'equity' => 3, 'provisions' => 0, 'liabilities' => 0];
		return [
			// Art. 2:365 — fixed asset classes reconcile.
			'365 classes reconcile' => ['bw-art365-fixed-assets-classes', $fixed + ['balanceSheet' => ['fixedAssets' => 60.004]], true],
			'365 classes off' => ['bw-art365-fixed-assets-classes', $fixed + ['balanceSheet' => ['fixedAssets' => 61]], false],
			'365 total not numeric' => ['bw-art365-fixed-assets-classes', $fixed + ['balanceSheet' => ['fixedAssets' => 'n/a']], false],
			'365 no balance sheet' => ['bw-art365-fixed-assets-classes', $fixed, false],
			'365 class missing' => ['bw-art365-fixed-assets-classes', ['fixedAssetClasses' => ['intangible' => 10, 'tangible' => 20], 'balanceSheet' => ['fixedAssets' => 30]], false],
			'365 classes not an array' => ['bw-art365-fixed-assets-classes', ['fixedAssetClasses' => 'x'], false],
			// Art. 2:369 / 2:373 — current assets and equity reconcile.
			'369 current assets reconcile' => ['bw-art369-current-assets', ['currentAssetClasses' => ['inventories' => 1, 'receivables' => 2, 'securities' => 0, 'cash' => 3], 'balanceSheet' => ['currentAssets' => 6]], true],
			'369 current asset not numeric' => ['bw-art369-current-assets', ['currentAssetClasses' => ['inventories' => 1, 'receivables' => 'lots', 'securities' => 0, 'cash' => 3], 'balanceSheet' => ['currentAssets' => 6]], false],
			'373 equity off' => ['bw-art373-equity-components', ['equityComponents' => ['issuedCapital' => 100, 'sharePremium' => 0, 'revaluationReserve' => 0, 'statutoryReserves' => 0, 'retainedResult' => 5], 'balanceSheet' => ['equity' => 100]], false],
			// Art. 2:375 — debts maturity.
			'375 maturity reconciles' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => $maturity, 'balanceSheet' => ['liabilities' => 100]], true],
			'375 maturity off' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => $maturity, 'balanceSheet' => ['liabilities' => 99]], false],
			'375 over five years exceeds long term' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => ['overFiveYears' => 61] + $maturity, 'balanceSheet' => ['liabilities' => 100]], false],
			'375 negative over five years' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => ['overFiveYears' => -1] + $maturity, 'balanceSheet' => ['liabilities' => 100]], false],
			'375 missing short term' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => ['longTerm' => 60, 'overFiveYears' => 0], 'balanceSheet' => ['liabilities' => 60]], false],
			'375 no liabilities total' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => $maturity, 'balanceSheet' => []], false],
			'375 maturity not an array' => ['bw-art375-debts-maturity', ['liabilitiesMaturity' => 100], false],
			// RJ 210 — development reserve and intangible life.
			'rj210 reserve covers capitalised costs' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500, 'developmentLegalReserve' => '500'], true],
			'rj210 reserve short' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500, 'developmentLegalReserve' => 499], false],
			'rj210 no reserve' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500], false],
			'rj210 nothing capitalised' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 0], true],
			'rj210 capitalised unknown' => ['rj-210-development-legal-reserve', [], false],
			'rj210 life within 20 years' => ['rj-210-intangible-max-life', ['intangibleAmortisationYears' => 20], true],
			'rj210 life over 20 years' => ['rj-210-intangible-max-life', ['intangibleAmortisationYears' => 21], false],
			'rj210 zero life' => ['rj-210-intangible-max-life', ['intangibleAmortisationYears' => 0], false],
			// SBR filing.
			'sbr small filed' => ['rgs-sbr-filing', ['sizeClass' => ' Small ', 'sbrFiled' => true], true],
			'sbr micro not filed' => ['rgs-sbr-filing', ['sizeClass' => 'micro', 'sbrFiled' => false], false],
			'sbr large not required' => ['rgs-sbr-filing', ['sizeClass' => 'large'], true],
			'sbr no size class' => ['rgs-sbr-filing', ['sbrFiled' => true], false],
			// HGB.
			'266 full balance any size' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'full', 'sizeClass' => 'large'], true],
			'266 abridged for small' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'Abridged', 'sizeClass' => 'small'], true],
			'266 abridged for large' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'abridged', 'sizeClass' => 'large'], false],
			'266 no detail level' => ['hgb-266-small-abridged-balance', ['sizeClass' => 'small'], false],
			'275 nature model complete' => ['hgb-275-gesamtkostenverfahren', ['plPresentationModel' => 'categorical', 'pnlNatureBreakdown' => ['materialExpense' => 1, 'personnelExpense' => 2, 'depreciation' => 3, 'inventoryChange' => 0, 'ownWorkCapitalised' => 0]], true],
			'275 nature model incomplete' => ['hgb-275-gesamtkostenverfahren', ['plPresentationModel' => 'categorical', 'pnlNatureBreakdown' => ['materialExpense' => 1]], false],
			'275 other model skips nature' => ['hgb-275-gesamtkostenverfahren', ['plPresentationModel' => 'functional'], true],
			'275 function model incomplete' => ['hgb-275-umsatzkostenverfahren', ['plPresentationModel' => ' Functional ', 'pnlFunctionBreakdown' => ['costOfGoodsSold' => 1, 'distributionCosts' => 2]], false],
			'257 retention sufficient' => ['hgb-257-retention', ['retentionYearsAccounts' => 10, 'retentionYearsCorrespondence' => '6'], true],
			'257 correspondence short' => ['hgb-257-retention', ['retentionYearsAccounts' => 10, 'retentionYearsCorrespondence' => 5], false],
			'257 accounts missing' => ['hgb-257-retention', ['retentionYearsCorrespondence' => 6], false],
			// PCG.
			'pcg balance as table' => ['pcg-balance-table-form', ['balanceSheet' => $bs, 'balancePresentation' => 'TABLE'], true],
			'pcg balance as list' => ['pcg-balance-table-form', ['balanceSheet' => $bs, 'balancePresentation' => 'list'], false],
			'pcg balance line missing' => ['pcg-balance-table-form', ['balanceSheet' => ['provisions' => 'x'] + $bs, 'balancePresentation' => 'table'], false],
			'pcg balance not an array' => ['pcg-balance-table-form', ['balanceSheet' => 1, 'balancePresentation' => 'table'], false],
			'pcg income statement as list' => ['pcg-income-statement-list', ['pnlResultBreakdown' => ['operatingResult' => 1, 'financialResult' => 0, 'exceptionalResult' => 0], 'pnlPresentation' => 'list'], true],
			'pcg income statement no presentation' => ['pcg-income-statement-list', ['pnlResultBreakdown' => ['operatingResult' => 1, 'financialResult' => 0, 'exceptionalResult' => 0]], false],
			'pcg abrege for small' => ['pcg-systeme-abrege', ['accountingSystem' => 'abrege', 'sizeClass' => 'micro'], true],
			'pcg abrege for medium' => ['pcg-systeme-abrege', ['accountingSystem' => 'abrege', 'sizeClass' => 'medium'], false],
			'pcg base not abrege' => ['pcg-systeme-abrege', ['accountingSystem' => 'base', 'sizeClass' => 'medium'], true],
			'pcg no system' => ['pcg-systeme-abrege', [], false],
			'pcg base for large' => ['pcg-systeme-base', ['accountingSystem' => 'Base', 'sizeClass' => 'large'], true],
			'pcg developpe for medium' => ['pcg-systeme-base', ['accountingSystem' => 'developpe', 'sizeClass' => 'medium'], false],
			'pcg small any system' => ['pcg-systeme-base', ['accountingSystem' => 'abrege', 'sizeClass' => 'small'], true],
			'pcg base, no system' => ['pcg-systeme-base', ['sizeClass' => 'large'], false],
			// French filing and retention.
			'confidential for small' => ['fr-confidentiality-option', ['confidentialFiling' => '1', 'sizeClass' => 'small'], true],
			'confidential for large' => ['fr-confidentiality-option', ['confidentialFiling' => true, 'sizeClass' => 'large'], false],
			'not confidential' => ['fr-confidentiality-option', ['confidentialFiling' => 'false', 'sizeClass' => 'large'], true],
			'confidentiality not stated' => ['fr-confidentiality-option', ['confidentialFiling' => 'maybe'], false],
			'fr retention short' => ['fr-retention-period', ['retentionYearsAccounts' => 9.5], false],
			'fec export with entries' => ['fec-mandatory-export', ['fecExportAvailable' => 1, 'fecEntries' => [['x']]], true],
			'fec export without entries' => ['fec-mandatory-export', ['fecExportAvailable' => true, 'fecEntries' => []], false],
			'fec export unavailable' => ['fec-mandatory-export', ['fecExportAvailable' => false, 'fecEntries' => [['x']]], false],
			'fec structure complete' => ['fec-field-structure', ['fecStructuredEntries' => [$fec, ['debit' => '0', 'credit' => '100'] + $fec]], true],
			'fec structure missing field' => ['fec-field-structure', ['fecStructuredEntries' => [['pieceRef' => ' '] + $fec]], false],
			'fec structure non-numeric debit' => ['fec-field-structure', ['fecStructuredEntries' => [['debit' => 'a'] + $fec]], false],
			'fec structure entry not an array' => ['fec-field-structure', ['fecStructuredEntries' => ['x']], false],
			'fec structure empty' => ['fec-field-structure', ['fecStructuredEntries' => []], false],
			// ESEF.
			'esef unlisted' => ['esef-block-tagging-notes', ['listedOnRegulatedMarket' => false], true],
			'esef listed and tagged' => ['esef-block-tagging-notes', ['listedOnRegulatedMarket' => true, 'notesBlockTagged' => 'true'], true],
			'esef listed untagged' => ['esef-block-tagging-notes', ['listedOnRegulatedMarket' => true, 'notesBlockTagged' => false], false],
			'esef listing unknown' => ['esef-block-tagging-notes', [], false],
			'esef no extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true, 'taxonomyExtensionsUsed' => false], true],
			'esef anchored extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true, 'taxonomyExtensionsUsed' => true, 'extensionsAnchored' => true], true],
			'esef unanchored extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true, 'taxonomyExtensionsUsed' => true], false],
			'esef extensions unknown' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true], false],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The financial statement checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $ruleId, array $object, bool $expected): void {
		$check = NationalReportingTailChecks::checks()['FinancialStatement'][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()
}//end class
