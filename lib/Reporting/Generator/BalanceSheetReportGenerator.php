<?php

/**
 * Balance sheet (balans) report generator
 *
 * Produces a balance sheet per peildatum, rendered as editable DOCX/ODT or as a
 * dompdf PDF from the default in-code layout. The figures are aggregated from
 * the real Account objects in the shillinq register: each account's balance is
 * classified by accountType (assets / liabilities / equity) and totalled into
 * the two sides of the balance. When account balances are not materialised on
 * the Account objects themselves, the balances are derived from the posted
 * GLLine movements (side + amount) per account.
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

/**
 * Renders a balance sheet from Account balances (assets / liabilities / equity).
 */
final class BalanceSheetReportGenerator extends AbstractStatementReportGenerator {
	/**
	 * The catalogue report-type id this generator produces.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	/**
	 * Cover title for the document.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Balans';
	}//end documentTitle()

	/**
	 * Build the balans body: assets vs. equity/liabilities from Account balances.
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
		$grouped = $this->groupByType($accounts, $administrationId);

		$this->addCover($section, 'Balans', 'Balans per peildatum', $context);
		$this->addAssets($run, $grouped);
		$this->addPassiva($run, $grouped);
		$this->addBalanceCheck($run, count($accounts));

		if ($accounts === []) {
			$this->addNote($section, 'Er zijn geen grootboekrekeningen gevonden voor deze administratie.');
		}

	}//end build()

	/**
	 * Balance-sheet account tables show the account balance.
	 *
	 * @return string
	 */
	protected function accountAmountHead(): string {
		return 'Saldo';
	}//end accountAmountHead()

	/**
	 * The Activa side: the asset accounts and their total (the debit side).
	 *
	 * @param StatementRun $run     The rendering in progress.
	 * @param array{assets: array<int, array{label: string, amount: float}>} $grouped The grouped accounts.
	 *
	 * @return void
	 */
	private function addAssets(StatementRun $run, array $grouped): void {
		$this->addHeading($run->section, 'Activa');
		$run->debitTotal = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal activa', lines: $grouped['assets']));
	}//end addAssets()

	/**
	 * The Passiva side: equity and liabilities, then their recapitulation
	 * (the credit side).
	 *
	 * @param StatementRun $run     The rendering in progress.
	 * @param array{equity: array<int, array{label: string, amount: float}>, liabilities: array<int, array{label: string, amount: float}>} $grouped The grouped accounts.
	 *
	 * @return void
	 */
	private function addPassiva(StatementRun $run, array $grouped): void {
		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Passiva');
		$recap = $this->addEquityAndDebts($run, $grouped);

		$run->section->addTextBreak(1);
		$this->addAmountTable(
			$run->section,
			'Recapitulatie passiva',
			'Bedrag',
			$recap->lines,
			['label' => $recap->totalLabel, 'amount' => $run->creditTotal],
			$run->currency
		);
	}//end addPassiva()

	/**
	 * The equity and the liability tables; sets the credit-side total and
	 * answers the recapitulation lines.
	 *
	 * @param StatementRun $run     The rendering in progress.
	 * @param array{equity: array<int, array{label: string, amount: float}>, liabilities: array<int, array{label: string, amount: float}>} $grouped The grouped accounts.
	 *
	 * @return AccountBucket The recapitulation of the passiva.
	 */
	private function addEquityAndDebts(StatementRun $run, array $grouped): AccountBucket {
		$run->section->addText('Eigen vermogen', 'amountBold', 'tight');
		$totalEquity = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal eigen vermogen', lines: $grouped['equity']));

		$run->section->addTextBreak(1);
		$run->section->addText('Schulden', 'amountBold', 'tight');
		$totalLiabilities = $this->addAccountTable($run, new AccountBucket(totalLabel: 'Totaal schulden', lines: $grouped['liabilities']));

		$run->creditTotal = ($totalEquity + $totalLiabilities);

		return new AccountBucket(
			totalLabel: 'Totaal passiva',
			lines: [
				['label' => 'Eigen vermogen', 'amount' => $totalEquity],
				['label' => 'Schulden', 'amount' => $totalLiabilities],
			]
		);
	}//end addEquityAndDebts()

	/**
	 * The Balanscontrole: account count, both side totals, their difference
	 * and whether the balance closes (within half a cent).
	 *
	 * @param StatementRun $run          The rendering in progress.
	 * @param int          $accountCount Number of general-ledger accounts read.
	 *
	 * @return void
	 */
	private function addBalanceCheck(StatementRun $run, int $accountCount): void {
		$run->section->addTextBreak(1);
		$this->addHeading($run->section, 'Balanscontrole');
		$difference = ($run->debitTotal - $run->creditTotal);
		$this->addDetailsTable(
			$run->section,
			[
				'Aantal grootboekrekeningen' => (string)$accountCount,
				'Totaal activa' => $this->money($run->debitTotal, $run->currency),
				'Totaal passiva' => $this->money($run->creditTotal, $run->currency),
				'Verschil' => $this->money($difference, $run->currency),
				'Balans sluit aan' => (abs($difference) <= 0.005 ? 'Ja' : 'Nee'),
			]
		);
	}//end addBalanceCheck()

	/**
	 * Group accounts into the three balance-sheet buckets, resolving each
	 * account's balance (materialised field or derived from GLLine movements).
	 *
	 * @param array<int, array<string, mixed>> $accounts The Account objects.
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{assets: array<int, array{label: string, amount: float}>, liabilities: array<int, array{label: string, amount: float}>, equity: array<int, array{label: string, amount: float}>}
	 */
	private function groupByType(array $accounts, string $administrationId): array {
		$grouped = ['assets' => [], 'liabilities' => [], 'equity' => []];
		$movements = null;

		foreach ($accounts as $account) {
			$bucket = $this->balanceBucket($account);
			if ($bucket === null) {
				// Skip P&L accounts (revenue/expenses) — they do not sit on the balance.
				continue;
			}

			$balance = $this->accountBalance($account);
			if ($balance === null) {
				$movements ??= $this->deriveMovements($administrationId);
				$balance = ($movements[$this->accountKey($account)] ?? 0.0);
			}

			$grouped[$bucket][] = ['label' => $this->accountLabel($account), 'amount' => $balance];
		}

		return $grouped;
	}//end groupByType()

	/**
	 * The balance-sheet bucket of an account, or null for a P&L account.
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return string|null assets / liabilities / equity, or null.
	 */
	private function balanceBucket(array $account): string|null {
		$type = strtolower($this->str($account, 'accountType', 'type', 'category'));

		return match ($type) {
			// `activa` stays: this arm is a deliberately BILINGUAL tolerance
			// list — its siblings still accept `vreemd-vermogen` and
			// `eigen-vermogen` — so it keeps reading rows the value migration
			// has not reached yet. Translating it produced a duplicate arm and
			// psalm caught it as a ParadoxicalCondition.
			'assets', 'asset', 'activa' => 'assets',
			'liabilities', 'liability', 'debts', 'vreemd-vermogen' => 'liabilities',
			'equity', 'eigen-vermogen' => 'equity',
			default => null,
		};
	}//end balanceBucket()

	/**
	 * Read a materialised balance field from an Account, or null when absent so
	 * the caller derives it from GLLine movements.
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return float|null
	 */
	private function accountBalance(array $account): ?float {
		foreach (['balance', 'currentBalance', 'closingBalance', 'endBalance', 'saldo'] as $key) {
			if (array_key_exists($key, $account) === true && is_numeric($account[$key]) === true) {
				return (float)$account[$key];
			}
		}

		return null;
	}//end accountBalance()
}//end class
