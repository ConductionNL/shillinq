<?php

/**
 * Unit tests for LawfulnessParagraph.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\PublicSector\LawfulnessParagraph;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RV-011: the paragraph totals over real-shaped GL transactions, lines,
 * accounts, reserves, a tolerance and findings.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\LawfulnessParagraph
 * @uses   \OCA\Shillinq\Service\LedgerActuals
 */
class LawfulnessParagraphTest extends TestCase {

	/**
	 * The rows per schema the records mock answers.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The filters each schema was read with.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A ledger for administration "gem" in 2026: expense account 4000,
	 * revenue account 8000, a reserve whose result account is 4980.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'Account' => [
				['id' => 'a1', 'accountNumber' => '4000', 'accountType' => 'expenses', 'administrationId' => 'gem'],
				['id' => 'a2', 'accountNumber' => '8000', 'accountType' => 'revenue', 'administrationId' => 'gem'],
			],
			'Reserve' => [
				['id' => 'r1', 'administrationId' => 'gem', 'balanceAccountNumber' => '0500', 'resultAccountNumber' => '4980'],
			],
			'Tolerantiegrens' => [
				['id' => 't1', 'administrationId' => 'gem', 'financialYear' => 2026, 'error_percentage' => 1.0, 'uncertainty_percentage' => 3.0],
			],
			'Rechtmatigheidsbevinding' => [
				['id' => 'f1', 'administrationId' => 'gem', 'financialYear' => 2026, 'kind' => 'error', 'amount_error' => 2500000, 'status' => 'open'],
			],
			'GLTransaction' => [
				['id' => 'tx1', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2026-03-01'],
			],
			'GLLine' => [
				['transactionId' => 'tx1', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 200000000],
				['transactionId' => 'tx1', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 200000000],
			],
		];
	}//end setUp()

	/**
	 * The service over the rows.
	 *
	 * @return LawfulnessParagraph
	 */
	private function paragraph(): LawfulnessParagraph {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->method('records')->willReturnCallback(
			function (string $schema, array $filters): array {
				$this->asked[$schema] = $filters;
				return ($this->rows[$schema] ?? []);
			}
		);

		return new LawfulnessParagraph(records: $records);
	}//end paragraph()

	/**
	 * EUR 200 million of expenses, a 1 percent error tolerance and one error
	 * of EUR 2.5 million: the tolerance reads EUR 2 million and the errors
	 * exceed it.
	 *
	 * @return void
	 */
	public function testTheSpecScenarioExceedsTheErrorTolerance(): void {
		$totals = $this->paragraph()->compute(administrationId: 'gem', financialYear: 2026);

		$this->assertSame(200000000.0, $totals['total_expenses_including_reserve_movements']);
		$this->assertSame(2000000.0, $totals['tolerance_error_amount']);
		$this->assertSame(6000000.0, $totals['tolerance_uncertainty_amount']);
		$this->assertSame(1.0, $totals['tolerance_limit_error_percentage']);
		$this->assertSame(2500000.0, $totals['total_identified_errors']);
		$this->assertSame(0.0, $totals['total_identified_uncertainties']);
		$this->assertFalse($totals['within_tolerance']);
		$this->assertSame(['f1'], $totals['bevindingen']);
		$this->assertSame(['administrationId' => 'gem', 'financialYear' => 2026], $this->asked['Rechtmatigheidsbevinding']);
	}//end testTheSpecScenarioExceedsTheErrorTolerance()

	/**
	 * The totals are a valid paragraph payload of the merged register.
	 *
	 * @return void
	 */
	public function testTheTotalsValidateAgainstTheRegister(): void {
		$paragraph = ['administrationId' => 'gem', 'financialYear' => 2026, 'status' => 'draft']
			+ $this->paragraph()->compute(administrationId: 'gem', financialYear: 2026);

		$this->assertSame([], RegisterSchema::errors(slug: 'Rechtmatigheidsparagraaf', object: $paragraph));
	}//end testTheTotalsValidateAgainstTheRegister()

	/**
	 * A finding at the tolerance is within it; an uncertainty over its own
	 * tolerance is not.
	 *
	 * @return void
	 */
	public function testBothSumsAreJudgedAgainstTheirOwnTolerance(): void {
		$this->rows['Rechtmatigheidsbevinding'] = [
			['id' => 'f1', 'administrationId' => 'gem', 'financialYear' => 2026, 'amount_error' => 2000000],
		];
		$this->assertTrue($this->paragraph()->compute(administrationId: 'gem', financialYear: 2026)['within_tolerance']);

		$this->rows['Rechtmatigheidsbevinding'][] = ['id' => 'f2', 'administrationId' => 'gem', 'financialYear' => 2026, 'amount_uncertainty' => 6000000.01];
		$totals = $this->paragraph()->compute(administrationId: 'gem', financialYear: 2026);

		$this->assertSame(6000000.01, $totals['total_identified_uncertainties']);
		$this->assertFalse($totals['within_tolerance']);
	}//end testBothSumsAreJudgedAgainstTheirOwnTolerance()

	/**
	 * Additions to a reserve count as expenses, withdrawals do not reduce
	 * them, and a credit on an expense account does.
	 *
	 * @return void
	 */
	public function testReserveAdditionsCountAndWithdrawalsDoNot(): void {
		$this->rows['GLTransaction'][] = ['id' => 'tx2', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2026-12-31'];
		$this->rows['GLLine'] = array_merge(
			$this->rows['GLLine'],
			[
				['transactionId' => 'tx2', 'accountNumber' => '4980', 'side' => 'debit', 'amount' => 1000000],
				['transactionId' => 'tx2', 'accountNumber' => '0500', 'side' => 'credit', 'amount' => 1000000],
				['transactionId' => 'tx2', 'accountNumber' => '4980', 'side' => 'credit', 'amount' => 300000],
				['transactionId' => 'tx2', 'accountNumber' => '4000', 'side' => 'credit', 'amount' => 0.5],
				['transactionId' => 'tx2', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 999],
			]
		);

		$this->assertSame(200999999.5, $this->paragraph()->compute(administrationId: 'gem', financialYear: 2026)['total_expenses_including_reserve_movements']);
	}//end testReserveAdditionsCountAndWithdrawalsDoNot()

	/**
	 * Draft transactions, other years and other administrations stay out.
	 *
	 * @return void
	 */
	public function testOnlyTheYearsPostedLinesOfTheAdministrationCount(): void {
		$this->rows['GLTransaction'] = array_merge(
			$this->rows['GLTransaction'],
			[
				['id' => 'draft', 'administrationId' => 'gem', 'state' => 'draft', 'postingDate' => '2026-05-01'],
				['id' => 'old', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2025-12-31'],
				['id' => 'other', 'administrationId' => 'elsewhere', 'state' => 'posted', 'postingDate' => '2026-05-01'],
			]
		);
		foreach (['draft', 'old', 'other'] as $ref) {
			$this->rows['GLLine'][] = ['transactionId' => $ref, 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 5];
		}

		$this->rows['Rechtmatigheidsbevinding'][] = ['id' => 'f9', 'administrationId' => 'elsewhere', 'financialYear' => 2026, 'amount_error' => 1];

		$totals = $this->paragraph()->compute(administrationId: 'gem', financialYear: 2026);

		$this->assertSame(200000000.0, $totals['total_expenses_including_reserve_movements']);
		$this->assertSame(['f1'], $totals['bevindingen']);
	}//end testOnlyTheYearsPostedLinesOfTheAdministrationCount()

	/**
	 * Without the year's tolerance nothing is computed.
	 *
	 * @return void
	 */
	public function testAYearWithoutAToleranceIsRefused(): void {
		$this->rows['Tolerantiegrens'] = [['id' => 't0', 'administrationId' => 'gem', 'financialYear' => 2025, 'error_percentage' => 1.0]];

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Record the tolerance for 2026');
		$this->paragraph()->compute(administrationId: 'gem', financialYear: 2026);
	}//end testAYearWithoutAToleranceIsRefused()

	/**
	 * A paragraph without an administration or a year is refused.
	 *
	 * @return void
	 */
	public function testAParagraphWithoutAYearIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->paragraph()->compute(administrationId: 'gem', financialYear: 0);
	}//end testAParagraphWithoutAYearIsRefused()
}//end class
