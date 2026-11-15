<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Profit and loss (winst- en verliesrekening) report generator
 *
 * Produces a resultatenrekening over the period, rendered as editable DOCX/ODT
 * or as a dompdf PDF from the default in-code layout. Revenue and expense totals
 * are aggregated from the real Account objects in the shillinq register
 * (accountType revenue / expenses); per-account amounts come from the
 * materialised account balance or, when absent, from the posted GLLine movements
 * (credit raises revenue, debit raises expenses). The result for the period is
 * revenue minus expenses.
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
 * Renders a profit-and-loss account from revenue/expense account totals.
 */
final class ProfitLossReportGenerator extends AbstractStatementReportGenerator {
	/**
	 * Dutch `accountType` tokens accepted on READ, normalised to the English
	 * bucket names this app uses internally.
	 *
	 * These are NOT app vocabulary and are deliberately not renamed. They are
	 * INPUT tokens: values that already sit in customer data or arrive from a
	 * bookkeeping import, where `baten` / `lasten` are the statutory terms an
	 * accountant's export actually writes. The adapter boundary is the one place
	 * the English-only rule exempts, and this is that boundary.
	 *
	 * The distinction matters because it was got wrong here twice: the vocabulary
	 * rename translated `'baten'` to `'revenue'` inside the match, producing a
	 * duplicate arm, so accounts typed `baten` matched nothing, fell through to
	 * `continue`, and VANISHED from the profit-and-loss report. Renaming a stored
	 * VALUE is a data migration, not an edit — which is why they live in a table
	 * the rename pass cannot silently collapse.
	 *
	 * Everything downstream is English: the buckets are `revenue` and `expense`,
	 * and no Dutch token escapes this class.
	 *
	 * @var array<string, string>
	 */
	private const ACCOUNT_TYPE_ALIASES = [
		'revenue' => 'revenue',
		'income' => 'revenue',
		'expenses' => 'expense',
		'expense' => 'expense',
		// Dutch, accepted on read only.
		'opbrengsten' => 'revenue',
		'baten' => 'revenue',
		'kosten' => 'expense',
		'lasten' => 'expense',
	];

	/**
	 * The catalogue report-type id this generator produces.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'profit-loss';
	}//end reportType()

	/**
	 * Cover title for the document.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Winst- en verliesrekening';
	}//end documentTitle()

	/**
	 * Build the W&V body: revenue, expenses, result, from account totals.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
	 *
	 * @return void
	 */
	protected function build(ReportSection $section, array $context): void {
		$administrationId = $this->str($context, 'administrationId');
		$accounts = $this->loadAccounts($administrationId);
		$run = new StatementRun(section: $section, currency: $this->accountsCurrency($accounts));

		[$revenueLines, $expenseLines, $movements] = $this->classify($accounts, $administrationId);

		$this->addCover($section, 'Winst- en verliesrekening', 'Resultatenrekening over de periode', $context);
		$this->addRevenueAndCosts($run, [$revenueLines, $expenseLines]);
		$this->addResult($run);
		$this->addBasisNote($run, $movements);

		if ($revenueLines === [] && $expenseLines === []) {
			$this->addNote($section, 'Er zijn geen resultaatrekeningen gevonden voor deze administratie.');
		}

	}//end build()

	/**
	 * The Opbrengsten and Kosten tables; sets the credit side (revenue) and
	 * the debit side (expenses).
	 *
	 * @param StatementRun                 $run   The rendering in progress.
	 * @param array{0: array<int, mixed>, 1: array<int, mixed>} $lines The revenue and the expense lines.
	 *
	 * @return void
	 */
	private function addRevenueAndCosts(StatementRun $run, array $lines): void {
		// --- Opbrengsten ---
		$this->addHeading($run->section, 'Opbrengsten');
		$run->creditTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal opbrengsten', lines: $lines[0]));

		// --- Kosten ---
		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Kosten');
		$run->debitTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal kosten', lines: $lines[1]));
	}//end addRevenueAndCosts()

	/**
	 * The Resultaat table: revenue minus expenses, labelled profit or loss.
	 *
	 * @param StatementRun $run The rendering in progress.
	 *
	 * @return void
	 */
	private function addResult(StatementRun $run): void {
		$result = ($run->creditTotal - $run->debitTotal);

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
	 * The note on how the result was determined.
	 *
	 * @param StatementRun $run           The rendering in progress.
	 * @param bool         $usedMovements Whether GLLine movements filled in missing amounts.
	 *
	 * @return void
	 */
	private function addBasisNote(StatementRun $run, bool $usedMovements): void {
		$run->section->addTextBreak(1);
		$this->addNote(
			$run->section,
			'Het resultaat is bepaald als het saldo van opbrengsten en kosten over de periode. '
			. 'Bedragen zijn afgeleid uit de grootboekrekeningen van het type opbrengst en kosten'
			. ($usedMovements === true ? ', aangevuld met de geboekte grootboekmutaties.' : '.')
		);
	}//end addBasisNote()

	/**
	 * Classify accounts into revenue and expense lines with their amounts.
	 *
	 * @param array<int, array<string, mixed>> $accounts The Account objects.
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{0: array<int, array{label: string, amount: float}>, 1: array<int, array{label: string, amount: float}>, 2: bool}
	 */
	private function classify(array $accounts, string $administrationId): array {
		$lines = ['revenue' => [], 'expense' => []];
		$movements = null;

		foreach ($accounts as $account) {
			$type = strtolower($this->str($account, 'accountType', 'type', 'category'));
			$bucket = (self::ACCOUNT_TYPE_ALIASES[$type] ?? null);
			if ($bucket === null) {
				continue;
			}

			$amount = $this->accountAmount($account);
			if ($amount === null) {
				$movements ??= $this->deriveMovements($administrationId);
				$net = ($movements[$this->accountKey($account)] ?? 0.0);
				// Net = debit - credit. Revenue is credit-natured, expense debit-natured.
				$amount = $bucket === 'revenue' ? (-1 * $net) : $net;
			}

			$lines[$bucket][] = ['label' => $this->accountLabel($account), 'amount' => abs($amount)];
		}

		return [$lines['revenue'], $lines['expense'], $movements !== null];
	}//end classify()

	/**
	 * Read a materialised amount/balance from a P&L Account, or null when absent.
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return float|null
	 */
	private function accountAmount(array $account): ?float {
		foreach (['balance', 'currentBalance', 'closingBalance', 'periodTotal', 'amount', 'saldo'] as $key) {
			if (array_key_exists($key, $account) === true && is_numeric($account[$key]) === true) {
				return (float)$account[$key];
			}
		}

		return null;
	}//end accountAmount()
}//end class
