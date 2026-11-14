<?php

/**
 * Behaviour of the national extra-tail checks on crafted statements, mandates and
 * SAF-T export profiles.
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

use OCA\Shillinq\Standards\Checks\NationalExtraTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each statement-schema, size-threshold, effective-date, filing-window, mandate
 * and SAF-T predicate passes on a complete object and fails on the defect it names.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalExtraTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalExtraTailChecksBehaviourTest extends TestCase {

	/**
	 * A statement with a complete balance-sheet and P&L layout.
	 *
	 * @return array<string, mixed>
	 */
	private static function statement(): array {
		return [
			'balanceSheet' => [
				'fixedAssets' => 100,
				'currentAssets' => '50.5',
				'equity' => 80,
				'provisions' => 0,
				'liabilities' => 70.5,
			],
			'profitAndLoss' => [
				'netTurnover' => 1000,
				'operatingExpenses' => 800,
				'financialResult' => -10,
				'result' => 190,
			],
			'components' => [' Balance ', 'PNL', 'notes'],
			'equityChangesStatementPresent' => true,
			'cashFlowStatementPresent' => 'true',
		];

	}//end statement()

	/**
	 * Run one rule on one object.
	 *
	 * @param string               $type   The object type.
	 * @param string               $ruleId The rule id.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return bool
	 */
	private static function check(string $type, string $ruleId, array $object): bool {
		return (NationalExtraTailChecks::checks()[$type][$ruleId])($object, []);

	}//end check()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function statementCases(): array {
		$full = self::statement();

		$noProvisions = $full;
		unset($noProvisions['balanceSheet']['provisions']);
		$textEquity = $full;
		$textEquity['balanceSheet']['equity'] = 'eighty';
		$noResult = $full;
		unset($noResult['profitAndLoss']['result']);
		$textTurnover = $full;
		$textTurnover['profitAndLoss']['netTurnover'] = 'n/a';
		$noNotes = $full;
		$noNotes['components'] = ['balance', 'pnl'];
		$noCashFlow = $full;
		$noCashFlow['cashFlowStatementPresent'] = false;

		return [
			'balance layout complete' => ['it-cc-art2424-balance-sheet-schema', $full, true],
			'balance layout missing provisions' => ['it-cc-art2424-balance-sheet-schema', $noProvisions, false],
			'balance layout non-numeric equity' => ['it-cc-art2424-balance-sheet-schema', $textEquity, false],
			'pnl layout complete' => ['it-cc-art2425-income-statement-schema', $full, true],
			'pnl layout missing result' => ['it-cc-art2425-income-statement-schema', $noResult, false],
			'pnl layout non-numeric turnover' => ['it-cc-art2425-income-statement-schema', $textTurnover, false],
			'components match trimmed and case-insensitive' => ['it-oic12-statement-structure', $full, true],
			'components without notes' => ['it-oic12-statement-structure', $noNotes, false],
			'normal model complete' => ['es-pgc-cuentas-anuales-normal', $full + ['statementModel' => 'normal'], true],
			'normal model without cash-flow statement' => ['es-pgc-cuentas-anuales-normal', $noCashFlow, false],
			'normal model without notes' => ['es-pgc-cuentas-anuales-normal', $noNotes, false],
			'abbreviated model needs no full layout' => ['es-pgc-cuentas-anuales-normal', ['statementModel' => 'Abreviado'], true],
		];

	}//end statementCases()

	/**
	 * The statement-schema rules read the nested layout and the components list.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('statementCases')]
	public function testStatementSchemaRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check('FinancialStatement', $ruleId, $object));

	}//end testStatementSchemaRules()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function sizeCases(): array {
		return [
			'abbreviated by a small entity' => ['it-cc-art2435bis-abbreviated', ['statementModel' => 'abbreviated', 'sizeClass' => ' Small '], true],
			'micro model by a micro entity' => ['it-cc-art2435bis-abbreviated', ['statementModel' => 'micro', 'sizeClass' => 'MICRO'], true],
			'short model by a large entity' => ['it-cc-art2435bis-abbreviated', ['statementModel' => 'short-form', 'sizeClass' => 'large'], false],
			'abbreviated without a size class' => ['it-cc-art2435bis-abbreviated', ['statementModel' => 'abbreviated'], false],
			'abreviado by a medium entity' => ['es-pgc-cuentas-anuales-abreviado', ['statementModel' => 'abreviado', 'sizeClass' => 'medium'], false],
			'pymes by a small entity' => ['es-pgc-pymes', ['statementModel' => 'PYMES', 'sizeClass' => 'small'], true],
			'pymes by a medium entity' => ['es-pgc-pymes', ['statementModel' => 'pymes', 'sizeClass' => 'medium'], false],
			'pymes without a size class' => ['es-pgc-pymes', ['statementModel' => 'pyme'], false],
			'model not declared' => ['be-car-annual-accounts-models', ['sizeClass' => 'small'], false],
			'full model by any entity' => ['be-car-annual-accounts-models', ['statementModel' => 'full', 'sizeClass' => 'large'], true],
			'micro model by a small entity' => ['be-car-annual-accounts-models', ['statementModel' => 'micro', 'sizeClass' => 'small'], true],
			'abbreviated model by a large entity' => ['be-car-annual-accounts-models', ['statementModel' => 'abbreviated', 'sizeClass' => 'large'], false],
		];

	}//end sizeCases()

	/**
	 * The abbreviated / PYMES / NBB-model rules gate on the declared size class.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('sizeCases')]
	public function testSizeThresholdRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check('FinancialStatement', $ruleId, $object));

	}//end testSizeThresholdRules()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function dateCases(): array {
		$filing = ['reportingPeriodEnd' => '2025-12-31', 'adoptionDate' => '2026-05-15'];

		return [
			'OIC 34 out of scope before 2024' => ['it-oic34-effective-2024', ['reportingPeriodStart' => '2023-01-01'], true],
			'OIC 34 in scope from 2024' => ['it-oic34-effective-2024', ['reportingPeriodStart' => '2024-01-01'], false],
			'OIC 34 with an unparseable start' => ['it-oic34-effective-2024', ['reportingPeriodStart' => 'not-a-date'], false],
			'ICAC 2021 out of scope before 2021' => ['es-pgc-norma14-icac-2021', ['reportingPeriodStart' => '2020-01-01T00:00:00Z'], true],
			'ICAC 2021 in scope in 2022' => ['es-pgc-norma14-icac-2021', ['reportingPeriodStart' => '2022-01-01'], false],
			'filed within both windows' => ['be-nbb-filing', $filing + ['filedDate' => '2026-06-14'], true],
			'filed on adoption day' => ['be-nbb-filing', $filing + ['filedDate' => '2026-05-15'], true],
			'filed more than 30 days after adoption' => ['be-nbb-filing', $filing + ['filedDate' => '2026-06-15'], false],
			'filed before adoption' => ['be-nbb-filing', $filing + ['filedDate' => '2026-05-14'], false],
			'filed within 30 days but past 7 months' => ['be-nbb-filing', ['reportingPeriodEnd' => '2025-12-31', 'adoptionDate' => '2026-07-20', 'filedDate' => '2026-08-01'], false],
			'filed before year-end' => ['be-nbb-filing', ['reportingPeriodEnd' => '2025-12-31', 'adoptionDate' => '2025-12-01', 'filedDate' => '2025-12-15'], false],
			'filed without a year-end' => ['be-nbb-filing', ['adoptionDate' => '2026-05-15', 'filedDate' => '2026-06-01'], false],
		];

	}//end dateCases()

	/**
	 * The effective-date and NBB filing-window rules compare the declared dates.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The statement.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('dateCases')]
	public function testDateRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check('FinancialStatement', $ruleId, $object));

	}//end testDateRules()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function mandateCases(): array {
		$base = ['enabled' => true, 'structuredFormat' => 'UBL'];

		return [
			'channel matches' => ['it-einvoice-sdi-b2b', $base + ['transmissionChannel' => ' sdi '], true],
			'format matches' => ['it-einvoice-sdi-b2b', ['enabled' => '1', 'structuredFormat' => 'FatturaPA 1.2'], true],
			'neither matches' => ['it-einvoice-sdi-b2b', $base + ['transmissionChannel' => 'email'], false],
			'no structured format' => ['it-einvoice-sdi-b2b', ['enabled' => true, 'structuredFormat' => ' ', 'transmissionChannel' => 'SdI'], false],
			'not enabled' => ['it-einvoice-sdi-b2b', ['enabled' => 'no', 'structuredFormat' => 'FatturaPA'], false],
			'Peppol live before go-live' => ['be-einvoice-b2b-peppol', $base + ['transmissionChannel' => 'Peppol', 'liveFrom' => '2025-12-31'], true],
			'Peppol live after go-live' => ['be-einvoice-b2b-peppol', $base + ['transmissionChannel' => 'Peppol', 'liveFrom' => '2026-01-02'], false],
			'Peppol without a live date' => ['be-einvoice-b2b-peppol', $base + ['transmissionChannel' => 'Peppol'], false],
			'VERI*FACTU without chained records' => ['es-verifactu-certified-software', $base + ['transmissionChannel' => 'AEAT'], false],
			'Portugal without ATCUD' => ['pt-saft-pt-billing', $base + ['transmissionChannel' => 'e-Fatura', 'qrCode' => true], false],
		];

	}//end mandateCases()

	/**
	 * The e-invoicing mandates need an enabled profile with a format, and match the
	 * network on the channel or the format.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The mandate.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('mandateCases')]
	public function testMandateRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check('EInvoiceMandate', $ruleId, $object));

	}//end testMandateRules()

	/**
	 * Cases: rule id, object, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function saftCases(): array {
		$areas = ['General-Ledger', 'accounts-receivable', 'accounts-payable', 'fixed-assets', ' inventory '];

		return [
			'standard names the country' => ['pt-saft-pt-accounting', ['capable' => true, 'standard' => 'PT 1.04'], true],
			'standard names the variant' => ['pl-jpk-saft', ['capable' => true, 'standard' => 'jpk_kr'], true],
			'standard names none' => ['pl-jpk-saft', ['capable' => true, 'standard' => 'proprietary'], false],
			'no standard declared' => ['pt-saft-pt-accounting', ['capable' => true], false],
			'not capable' => ['pt-saft-pt-accounting', ['capable' => false, 'standard' => 'SAF-T'], false],
			'D406 covers every area' => ['ro-saft-d406', ['capable' => true, 'standard' => 'D406', 'coveredAreas' => $areas], true],
			'D406 misses inventory' => ['ro-saft-d406', ['capable' => true, 'standard' => 'D406', 'coveredAreas' => array_slice($areas, 0, 4)], false],
			'D406 without an areas list' => ['ro-saft-d406', ['capable' => true, 'standard' => 'D406', 'coveredAreas' => 'general-ledger'], false],
			'FEC not on demand' => ['fr-fec-on-demand', ['capable' => true, 'standard' => 'FEC'], false],
		];

	}//end saftCases()

	/**
	 * The SAF-T rules need a capable profile with a named standard, and D406 every area.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The export profile.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('saftCases')]
	public function testSaftRules(string $ruleId, array $object, bool $expected): void {
		$this->assertSame($expected, self::check('SaftExportProfile', $ruleId, $object));

	}//end testSaftRules()
}//end class
