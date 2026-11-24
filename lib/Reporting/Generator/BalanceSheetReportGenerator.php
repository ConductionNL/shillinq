<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Balance sheet (balans) report generator
 *
 * Produces a balance sheet per peildatum, rendered as editable DOCX/ODT or as a
 * dompdf PDF from the default in-code layout. The figures come from
 * StatementCalculator::balancesAsOf at the peildatum, the same figures the
 * balance sheet page shows (reporting-financial-statements REQ-RFS-004):
 * posted, unreversed ledger lines up to that date, with the running result of
 * the fiscal year in equity, never stored account balances.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Reporting\StatementPeriods;
use Throwable;

/**
 * Renders a balance sheet from the statement calculator's figures.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
final class BalanceSheetReportGenerator extends AbstractStatementReportGenerator {

	/**
	 * Report type identifier.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	/**
	 * Document title.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Balans';
	}//end documentTitle()

	/**
	 * Build the balance sheet into the report section.
	 *
	 * @param ReportSection        $section The section to render into.
	 * @param array<string, mixed> $context administrationId, period, asOf, to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	protected function build(ReportSection $section, array $context): void {
		$statement = $this->statement($context);
		$run = new StatementRun(section: $section, currency: 'EUR');

		$this->addCover($section, 'Balans', 'Balans per ' . $statement['asOf'], $context);
		$this->addAssets($run, $statement);
		$this->addPassiva($run, $statement);
		$this->addBalanceCheck($run);

		if ($statement['assets'] === [] && $statement['liabilities'] === [] && $statement['equity'] === []) {
			$this->addNote($section, 'Er zijn geen geboekte grootboekregels gevonden voor deze administratie tot deze datum.');
		}
	}//end build()

	/**
	 * Header of the amount column.
	 *
	 * @return string
	 */
	protected function accountAmountHead(): string {
		return 'Saldo';
	}//end accountAmountHead()

	/**
	 * The calculator's balance sheet for the context, empty when it is unavailable.
	 *
	 * @param array<string, mixed> $context The generation context.
	 *
	 * @return array<string, mixed> asOf, assets, liabilities, equity.
	 */
	private function statement(array $context): array {
		$asOf = $this->asOf($context);
		$calculator = $this->calculator();
		if ($calculator === null) {
			return ['asOf' => $asOf, 'assets' => [], 'liabilities' => [], 'equity' => []];
		}

		return $calculator->balancesAsOf(administrationId: $this->str($context, 'administrationId'), asOf: $asOf);
	}//end statement()

	/**
	 * The peildatum: an explicit `asOf`, else the end of the context's range.
	 *
	 * @param array<string, mixed> $context The generation context.
	 *
	 * @return string YYYY-MM-DD.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	private function asOf(array $context): string {
		$asOf = $this->str($context, 'asOf');
		if ($asOf !== '') {
			try {
				StatementPeriods::date(date: $asOf, field: 'asOf');
				return $asOf;
			} catch (Throwable $e) {
				// An unreadable asOf falls back to the end of the period.
			}
		}

		return $this->contextRange($context)['to'];
	}//end asOf()

	/**
	 * Render the assets side.
	 *
	 * @param StatementRun         $run       The statement being rendered.
	 * @param array<string, mixed> $statement The calculator's balance sheet.
	 *
	 * @return void
	 */
	private function addAssets(StatementRun $run, array $statement): void {
		$this->addHeading($run->section, 'Activa');
		$run->debitTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal activa', lines: $statement['assets']));
	}//end addAssets()

	/**
	 * Render equity, debts and the passiva recap.
	 *
	 * @param StatementRun         $run       The statement being rendered.
	 * @param array<string, mixed> $statement The calculator's balance sheet.
	 *
	 * @return void
	 */
	private function addPassiva(StatementRun $run, array $statement): void {
		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Passiva');

		$run->section->addText('Eigen vermogen', 'amountBold', 'tight');
		$totalEquity = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal eigen vermogen', lines: $statement['equity']));

		$run->section->addTextBreak(1);
		$run->section->addText('Schulden', 'amountBold', 'tight');
		$totalLiabilities = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal schulden', lines: $statement['liabilities']));

		$run->creditTotal = round(($totalEquity + $totalLiabilities), 2);

		$run->section->addTextBreak(1);
		$this->addAmountTable(
			$run->section,
			'Recapitulatie passiva',
			'Bedrag',
			[
				['label' => 'Eigen vermogen', 'amount' => $totalEquity],
				['label' => 'Schulden', 'amount' => $totalLiabilities],
			],
			['label' => 'Totaal passiva', 'amount' => $run->creditTotal],
			$run->currency
		);
	}//end addPassiva()

	/**
	 * Render the check that both sides agree.
	 *
	 * @param StatementRun $run The statement being rendered.
	 *
	 * @return void
	 */
	private function addBalanceCheck(StatementRun $run): void {
		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Balanscontrole');
		$difference = round(($run->debitTotal - $run->creditTotal), 2);
		$this->addDetailsTable(
			$run->section,
			[
				'Totaal activa' => $this->money($run->debitTotal, $run->currency),
				'Totaal passiva' => $this->money($run->creditTotal, $run->currency),
				'Verschil' => $this->money($difference, $run->currency),
				'Balans sluit aan' => (abs($difference) <= 0.005 ? 'Ja' : 'Nee'),
			]
		);
	}//end addBalanceCheck()
}//end class
