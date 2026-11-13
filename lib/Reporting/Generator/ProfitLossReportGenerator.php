<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Profit and loss (winst- en verliesrekening) report generator
 *
 * Produces a resultatenrekening over the period, rendered as editable DOCX/ODT
 * or as a dompdf PDF from the default in-code layout. The figures come from
 * StatementCalculator::resultsBetween over the period's range, the same
 * figures the profit and loss page shows (reporting-financial-statements
 * REQ-RFS-004): posted, unreversed ledger lines, never stored account
 * balances. The result for the period is revenue minus expenses.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\ReportSection;

/**
 * Renders a profit-and-loss account from the statement calculator's figures.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
final class ProfitLossReportGenerator extends AbstractStatementReportGenerator {

	/**
	 * Report type identifier.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'profit-loss';
	}//end reportType()

	/**
	 * Document title.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Winst- en verliesrekening';
	}//end documentTitle()

	/**
	 * Build the profit and loss into the report section.
	 *
	 * @param ReportSection        $section The section to render into.
	 * @param array<string, mixed> $context administrationId, period, from, to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	protected function build(ReportSection $section, array $context): void {
		$statement = $this->statement($context);
		$run = new StatementRun(section: $section, currency: 'EUR');

		$this->addCover($section, 'Winst- en verliesrekening', 'Resultatenrekening over de periode', $context);
		$this->addRevenueAndCosts($run, [$statement['revenue'], $statement['expense']]);
		$this->addResult($run);
		$this->addBasisNote($run, $statement);

		if ($statement['revenue'] === [] && $statement['expense'] === []) {
			$this->addNote($section, 'Er zijn geen geboekte resultaatregels gevonden voor deze administratie in deze periode.');
		}
	}//end build()

	/**
	 * The calculator's profit and loss for the context, empty when it is unavailable.
	 *
	 * @param array<string, mixed> $context The generation context.
	 *
	 * @return array<string, mixed> from, to, revenue, expense.
	 */
	private function statement(array $context): array {
		$range = $this->contextRange($context);
		$calculator = $this->calculator();
		if ($calculator === null) {
			return $range + ['revenue' => [], 'expense' => []];
		}

		return $calculator->resultsBetween(
			administrationId: $this->str($context, 'administrationId'),
			from: $range['from'],
			to: $range['to']
		);
	}//end statement()

	/**
	 * Render the revenue and cost tables.
	 *
	 * @param StatementRun                                 $run   The statement being rendered.
	 * @param array{0: array<int, array>, 1: array<int, array>} $lines Revenue lines, expense lines.
	 *
	 * @return void
	 */
	private function addRevenueAndCosts(StatementRun $run, array $lines): void {
		$this->addHeading($run->section, 'Opbrengsten');
		$run->creditTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal opbrengsten', lines: $lines[0]));

		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Kosten');
		$run->debitTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal kosten', lines: $lines[1]));
	}//end addRevenueAndCosts()

	/**
	 * Render the result: revenue minus costs.
	 *
	 * @param StatementRun $run The statement being rendered.
	 *
	 * @return void
	 */
	private function addResult(StatementRun $run): void {
		$result = round(($run->creditTotal - $run->debitTotal), 2);

		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Resultaat');
		$this->addAmountTable(
			$run->section,
			'Resultaatbepaling',
			'Bedrag',
			[
				['label' => 'Totaal opbrengsten', 'amount' => $run->creditTotal],
				['label' => 'Totaal kosten', 'amount' => (-1 * $run->debitTotal)],
			],
			['label' => ($result >= 0.0 ? 'Resultaat (winst)' : 'Resultaat (verlies)'), 'amount' => $result],
			$run->currency
		);
	}//end addResult()

	/**
	 * Render the note on how the figures were determined.
	 *
	 * @param StatementRun         $run       The statement being rendered.
	 * @param array<string, mixed> $statement The calculator's statement.
	 *
	 * @return void
	 */
	private function addBasisNote(StatementRun $run, array $statement): void {
		$run->section->addTextBreak(1);
		$this->addNote(
			$run->section,
			'Het resultaat is het saldo van opbrengsten en kosten over de periode '
			. $statement['from'] . ' tot en met ' . $statement['to'] . ', '
			. 'berekend uit de geboekte, niet tegengeboekte grootboekregels.'
		);
	}//end addBasisNote()
}//end class
