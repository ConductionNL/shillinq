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
final class BalanceSheetReportGenerator extends AbstractDocumentReportGenerator {
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

		$currency = 'EUR';
		foreach ($accounts as $account) {
			$accountCurrency = $this->str($account, 'currency');
			if ($accountCurrency !== '') {
				$currency = $accountCurrency;
				break;
			}
		}

		$grouped = $this->groupByType($accounts, $administrationId);

		$this->addCover($section, 'Balans', 'Balans per peildatum', $context);

		// --- Activa ---
		$this->addHeading($section, 'Activa');
		$assetLines = $this->lines($grouped['assets']);
		$totalAssets = $this->total($grouped['assets']);
		$this->addAmountTable(
			$section,
			'Rekening',
			'Saldo',
			$assetLines,
			['label' => 'Totaal activa', 'amount' => $totalAssets],
			$currency
		);

		// --- Passiva (equity + liabilities) ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Passiva');

		$equityLines = $this->lines($grouped['equity']);
		$liabilityLines = $this->lines($grouped['liabilities']);
		$totalEquity = $this->total($grouped['equity']);
		$totalLiabilities = $this->total($grouped['liabilities']);

		$section->addText('Eigen vermogen', 'amountBold', 'tight');
		$this->addAmountTable(
			$section,
			'Rekening',
			'Saldo',
			$equityLines,
			['label' => 'Totaal eigen vermogen', 'amount' => $totalEquity],
			$currency
		);

		$section->addTextBreak(1);
		$section->addText('Schulden', 'amountBold', 'tight');
		$this->addAmountTable(
			$section,
			'Rekening',
			'Saldo',
			$liabilityLines,
			['label' => 'Totaal schulden', 'amount' => $totalLiabilities],
			$currency
		);

		$section->addTextBreak(1);
		$this->addAmountTable(
			$section,
			'Recapitulatie passiva',
			'Bedrag',
			[
				['label' => 'Eigen vermogen', 'amount' => $totalEquity],
				['label' => 'Schulden', 'amount' => $totalLiabilities],
			],
			['label' => 'Totaal passiva', 'amount' => ($totalEquity + $totalLiabilities)],
			$currency
		);

		// --- Balanscontrole ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Balanscontrole');
		$totalCredit = ($totalEquity + $totalLiabilities);
		$difference = ($totalAssets - $totalCredit);
		$this->addDetailsTable(
			$section,
			[
				'Aantal grootboekrekeningen' => (string)count($accounts),
				'Totaal activa' => $this->money($totalAssets, $currency),
				'Totaal passiva' => $this->money($totalCredit, $currency),
				'Verschil' => $this->money($difference, $currency),
				'Balans sluit aan' => (abs($difference) <= 0.005 ? 'Ja' : 'Nee'),
			]
		);

		if ($accounts === []) {
			$this->addNote($section, 'Er zijn geen grootboekrekeningen gevonden voor deze administratie.');
		}

	}//end build()

	/**
	 * Load the Account objects for the administration (all accounts when no
	 * administration is supplied).
	 *
	 * @param string $administrationId The administration scope (may be empty).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAccounts(string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$accounts = $this->loadObjects('Account', $filters);
		if ($accounts === [] && $filters !== []) {
			// Some installs do not scope Account by administration — fall back to all.
			$accounts = $this->loadObjects('Account');
		}

		return $this->dedupeAccounts($accounts);
	}//end loadAccounts()

	/**
	 * Collapse the chart of accounts to one row per (administration, account key)
	 * so an unscoped read does not list the same general-ledger account multiple
	 * times. The first occurrence wins.
	 *
	 * @param array<int, array<string, mixed>> $accounts The Account objects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dedupeAccounts(array $accounts): array {
		$seen = [];
		$out = [];
		foreach ($accounts as $account) {
			$key = $this->str($account, 'administrationId') . '|' . $this->accountKey($account);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$out[] = $account;
		}

		return $out;
	}//end dedupeAccounts()

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
			$type = strtolower($this->str($account, 'accountType', 'type', 'category'));
			$bucket = match ($type) {
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

			if ($bucket === null) {
				// Skip P&L accounts (revenue/expenses) — they do not sit on the balance.
				continue;
			}

			$balance = $this->accountBalance($account);
			if ($balance === null) {
				if ($movements === null) {
					$movements = $this->deriveMovements($administrationId);
				}

				$code = $this->accountKey($account);
				$balance = ($movements[$code] ?? 0.0);
			}

			$label = $this->accountLabel($account);
			$grouped[$bucket][] = ['label' => $label, 'amount' => $balance];
		}//end foreach

		return $grouped;
	}//end groupByType()

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

	/**
	 * Derive net balances per account code from the posted GLLine movements
	 * (debit positive, credit negative), scoped to the administration.
	 *
	 * @param string $administrationId The administration scope (may be empty).
	 *
	 * @return array<string, float> accountNumber => net balance.
	 */
	private function deriveMovements(string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$lines = $this->loadObjects('GLLine', $filters);
		if ($lines === [] && $filters !== []) {
			$lines = $this->loadObjects('GLLine');
		}

		$balances = [];
		foreach ($lines as $line) {
			$code = $this->str($line, 'accountNumber', 'accountId', 'account');
			if ($code === '') {
				continue;
			}

			$amount = $this->num($line, 'amount', 'baseCurrencyAmount', 'transactionAmount');
			$side = strtolower($this->str($line, 'side', 'drcr'));
			$signed = $side === 'credit' ? (-1 * $amount) : $amount;

			$balances[$code] = (($balances[$code] ?? 0.0) + $signed);
		}

		return $balances;
	}//end deriveMovements()

	/**
	 * The account's join key (accountNumber preferred, then code/rgsCode/id).
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return string
	 */
	private function accountKey(array $account): string {
		return $this->str($account, 'accountNumber', 'code', 'rgsCode', 'id');
	}//end accountKey()

	/**
	 * A display label for an account row: "<number> <name>".
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return string
	 */
	private function accountLabel(array $account): string {
		$number = $this->str($account, 'accountNumber', 'code', 'rgsCode');
		$name = $this->str($account, 'name', 'accountName', 'title', 'description');
		$label = trim($number . ' ' . $name);
		return $label !== '' ? $label : ('Rekening ' . $this->str($account, 'id'));
	}//end accountLabel()

	/**
	 * Convert a grouped bucket into amount-table line shapes.
	 *
	 * @param array<int, array{label: string, amount: float}> $bucket The bucket rows.
	 *
	 * @return array<int, array{label: string, amount: float}>
	 */
	private function lines(array $bucket): array {
		return $bucket;
	}//end lines()

	/**
	 * Sum the amounts in a bucket.
	 *
	 * @param array<int, array{label: string, amount: float}> $bucket The bucket rows.
	 *
	 * @return float
	 */
	private function total(array $bucket): float {
		$sum = 0.0;
		foreach ($bucket as $row) {
			$sum += (float)($row['amount'] ?? 0.0);
		}

		return $sum;
	}//end total()
}//end class
