<?php

/**
 * Behaviour of the national reporting tail checks on crafted financial statements.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\NationalReportingTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The NL/DE/FR/ESEF statement rules pass on a reconciling statement and fail on the
 * defect each one names: a missing or non-numeric class, a total that does not
 * reconcile, a size class that does not permit the chosen simplification, a
 * malformed FEC entry or an unanchored ESEF extension.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalReportingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalReportingTailChecksRulesTest extends TestCase {

	/**
	 * A statement whose class breakdowns reconcile to its balance sheet.
	 *
	 * @return array<string, mixed>
	 */
	private static function statement(): array {
		return [
			'balanceSheet' => [
				'fixedAssets' => 600.0,
				'currentAssets' => 400.0,
				'equity' => 500.0,
				'provisions' => 0,
				'liabilities' => 500.0,
			],
			'fixedAssetClasses' => ['intangible' => 100.0, 'tangible' => '300.004', 'financial' => 200],
			'currentAssetClasses' => ['inventories' => 100, 'receivables' => 100, 'securities' => 0, 'cash' => 200],
			'liabilitiesMaturity' => ['shortTerm' => 200.0, 'longTerm' => 300.0, 'overFiveYears' => 100.0],
		];

	}//end statement()

	/**
	 * Run one FinancialStatement rule on one object.
	 *
	 * @param string               $ruleId The rule id.
	 * @param array<string, mixed> $object The statement.
	 *
	 * @return bool
	 */
	private static function check(string $ruleId, array $object): bool {
		return (NationalReportingTailChecks::checks()['FinancialStatement'][$ruleId])($object, []);

	}//end check()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function reconciliationCases(): array {
		$s = self::statement();

		$offByCent = $s;
		$offByCent['fixedAssetClasses']['financial'] = 200.02;
		$noTotal = $s;
		unset($noTotal['balanceSheet']['fixedAssets']);
		$noBalance = $s;
		unset($noBalance['balanceSheet']);
		$cashText = $s;
		$cashText['currentAssetClasses']['cash'] = 'two hundred';
		$noCash = $s;
		unset($noCash['currentAssetClasses']['cash']);

		return [
			'fixed-asset classes reconcile' => ['bw-art365-fixed-assets-classes', $s, true],
			'fixed-asset classes off by two cents' => ['bw-art365-fixed-assets-classes', $offByCent, false],
			'fixed-asset total missing' => ['bw-art365-fixed-assets-classes', $noTotal, false],
			'balance sheet missing' => ['bw-art365-fixed-assets-classes', $noBalance, false],
			'current-asset classes reconcile' => ['bw-art369-current-assets', $s, true],
			'current-asset class non-numeric' => ['bw-art369-current-assets', $cashText, false],
			'current-asset class missing' => ['bw-art369-current-assets', $noCash, false],
			'current-asset classes not a list' => ['bw-art369-current-assets', ['currentAssetClasses' => 'all'] + $s, false],
		];

	}//end reconciliationCases()

	/**
	 * The class breakdowns must be numeric and sum to the balance-sheet total.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('reconciliationCases')]
	public function testClassBreakdownReconciliation(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check($ruleId, $object));

	}//end testClassBreakdownReconciliation()

	/**
	 * Cases: object changes over the base statement, expected verdict.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function maturityCases(): array {
		return [
			'portions reconcile' => [[], true],
			'over five years above long term' => [['overFiveYears' => 300.01], false],
			'negative over five years' => [['overFiveYears' => -1], false],
			'portions do not reconcile' => [['shortTerm' => 199.0], false],
			'over five years missing' => [['overFiveYears' => null], false],
		];

	}//end maturityCases()

	/**
	 * The debt-maturity portions are a consistent split of the liabilities total.
	 *
	 * @param array<string, mixed> $changes  Changes to the maturity split.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('maturityCases')]
	public function testDebtsMaturity(array $changes, bool $expected): void {
		$s = self::statement();
		$s['liabilitiesMaturity'] = array_merge($s['liabilitiesMaturity'], $changes);

		$this->assertSame($expected, self::check('bw-art375-debts-maturity', $s));

	}//end testDebtsMaturity()

	/**
	 * Without a numeric liabilities total the maturity split cannot reconcile.
	 *
	 * @return void
	 */
	public function testDebtsMaturityNeedsTheLiabilitiesTotal(): void {
		$s = self::statement();
		$s['balanceSheet']['liabilities'] = 'n/a';

		$this->assertFalse(self::check('bw-art375-debts-maturity', $s));
		$this->assertFalse(self::check('bw-art375-debts-maturity', ['liabilitiesMaturity' => 'none']));

	}//end testDebtsMaturityNeedsTheLiabilitiesTotal()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function sizeCases(): array {
		return [
			'development costs need a covering reserve' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500, 'developmentLegalReserve' => 499.99], false],
			'development reserve covers the costs' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500, 'developmentLegalReserve' => '500'], true],
			'development reserve missing' => ['rj-210-development-legal-reserve', ['capitalisedDevelopmentCosts' => 500], false],
			'small entity filed via SBR' => ['rgs-sbr-filing', ['sizeClass' => ' Small ', 'sbrFiled' => true], true],
			'micro entity not filed via SBR' => ['rgs-sbr-filing', ['sizeClass' => 'micro', 'sbrFiled' => false], false],
			'large entity outside SBR scope' => ['rgs-sbr-filing', ['sizeClass' => 'large'], true],
			'SBR without a size class' => ['rgs-sbr-filing', ['sbrFiled' => true], false],
			'abridged balance by a small entity' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'Abridged', 'sizeClass' => 'small'], true],
			'abridged balance by a large entity' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'abridged', 'sizeClass' => 'large'], false],
			'full balance by a large entity' => ['hgb-266-small-abridged-balance', ['balanceDetailLevel' => 'full', 'sizeClass' => 'large'], true],
			'abridged system by a medium entity' => ['pcg-systeme-abrege', ['accountingSystem' => 'abrege', 'sizeClass' => 'medium'], false],
			'base system by a medium entity' => ['pcg-systeme-abrege', ['accountingSystem' => 'base', 'sizeClass' => 'medium'], true],
			'no accounting system declared' => ['pcg-systeme-abrege', ['sizeClass' => 'small'], false],
			'large entity on the base system' => ['pcg-systeme-base', ['accountingSystem' => 'Base', 'sizeClass' => 'large'], true],
			'large entity on the abridged system' => ['pcg-systeme-base', ['accountingSystem' => 'abrege', 'sizeClass' => 'large'], false],
			'small entity not bound to the base system' => ['pcg-systeme-base', ['accountingSystem' => 'abrege', 'sizeClass' => 'small'], true],
			'base system without a declared system' => ['pcg-systeme-base', ['sizeClass' => 'large'], false],
			'confidential filing by a micro entity' => ['fr-confidentiality-option', ['confidentialFiling' => '1', 'sizeClass' => 'micro'], true],
			'confidential filing by a medium entity' => ['fr-confidentiality-option', ['confidentialFiling' => true, 'sizeClass' => 'medium'], false],
			'confidentiality flag undecidable' => ['fr-confidentiality-option', ['confidentialFiling' => 'maybe'], false],
		];

	}//end sizeCases()

	/**
	 * The reserve, SBR and simplification rules gate on the amounts and size class.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('sizeCases')]
	public function testSizeGatedRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check($ruleId, $object));

	}//end testSizeGatedRules()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function fecAndEsefCases(): array {
		$entry = [
			'journalCode' => 'VE',
			'entryNum' => '1',
			'ecritureDate' => '2025-01-31',
			'compteNum' => '411000',
			'pieceRef' => 'FA-1',
			'debit' => 10,
			'credit' => '10.00',
		];
		$noPiece = $entry;
		$noPiece['pieceRef'] = ' ';
		$noCredit = $entry;
		unset($noCredit['credit']);
		$balance = ['balanceSheet' => self::statement()['balanceSheet']];
		$noProvisions = $balance;
		unset($noProvisions['balanceSheet']['provisions']);
		$textEquity = $balance;
		$textEquity['balanceSheet']['equity'] = 'half';

		return [
			'FEC entries well-formed' => ['fec-field-structure', ['fecStructuredEntries' => [$entry, $entry]], true],
			'FEC entry without a piece reference' => ['fec-field-structure', ['fecStructuredEntries' => [$entry, $noPiece]], false],
			'FEC entry without a credit' => ['fec-field-structure', ['fecStructuredEntries' => [$noCredit]], false],
			'FEC entry not a record' => ['fec-field-structure', ['fecStructuredEntries' => ['1;VE;411000']], false],
			'FEC without entries' => ['fec-field-structure', ['fecStructuredEntries' => []], false],
			'unlisted issuer outside ESEF' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => 'false'], true],
			'listed issuer without extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true, 'taxonomyExtensionsUsed' => false], true],
			'listed issuer with anchored extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => 1, 'taxonomyExtensionsUsed' => '1', 'extensionsAnchored' => 'true'], true],
			'listed issuer with unanchored extensions' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true, 'taxonomyExtensionsUsed' => true], false],
			'extensions flag undecidable' => ['esef-taxonomy-extension', ['listedOnRegulatedMarket' => true], false],
			'listing flag undecidable' => ['esef-block-tagging-notes', ['notesBlockTagged' => true], false],
			'listed issuer with tagged notes' => ['esef-block-tagging-notes', ['listedOnRegulatedMarket' => '1', 'notesBlockTagged' => true], true],
			'PCG balance in table form' => ['pcg-balance-table-form', $balance + ['balancePresentation' => ' Table '], true],
			'PCG balance in list form' => ['pcg-balance-table-form', $balance + ['balancePresentation' => 'list'], false],
			'PCG balance without provisions' => ['pcg-balance-table-form', $noProvisions + ['balancePresentation' => 'table'], false],
			'PCG balance with text equity' => ['pcg-balance-table-form', $textEquity + ['balancePresentation' => 'table'], false],
		];

	}//end fecAndEsefCases()

	/**
	 * FEC entries carry every structural field; listed issuers tag and anchor; the
	 * PCG balance is a complete table.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('fecAndEsefCases')]
	public function testFecAndEsefRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check($ruleId, $object));

	}//end testFecAndEsefRules()
}//end class
