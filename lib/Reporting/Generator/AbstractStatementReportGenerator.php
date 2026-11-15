<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Statement report generator base
 *
 * Shared account loading for the financial-statement generators (balance
 * sheet, profit and loss): the chart of accounts for an administration, one
 * row per (administration, account key), falling back to every account when
 * the install does not scope Account by administration; the net GLLine
 * movement per account; account keys, labels, the reporting currency and the
 * account-table layout both statements use.
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

/**
 * Base for the generators that render a statement from Account totals.
 */
abstract class AbstractStatementReportGenerator extends AbstractDocumentReportGenerator {

	/**
	 * Header of the amount column in the statement's account tables.
	 *
	 * @return string
	 */
	protected function accountAmountHead(): string {
		return 'Bedrag';
	}//end accountAmountHead()

	/**
	 * Load the Account objects for the administration (all accounts when no
	 * administration is supplied, or when the scoped read finds none), one row
	 * per (administration, account key).
	 *
	 * @param string $administrationId The administration scope (may be empty).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function loadAccounts(string $administrationId): array {
		return $this->dedupeAccounts($this->loadScoped('Account', $administrationId));
	}//end loadAccounts()

	/**
	 * Derive net movements per account code from the posted GLLine movements
	 * (debit positive, credit negative), scoped to the administration.
	 *
	 * @param string $administrationId The administration scope (may be empty).
	 *
	 * @return array<string, float> accountNumber => net (debit - credit).
	 */
	protected function deriveMovements(string $administrationId): array {
		$balances = [];
		foreach ($this->loadScoped('GLLine', $administrationId) as $line) {
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
	 * The reporting currency: the first account's currency, else EUR.
	 *
	 * @param array<int, array<string, mixed>> $accounts The Account objects.
	 *
	 * @return string
	 */
	protected function accountsCurrency(array $accounts): string {
		foreach ($accounts as $account) {
			$accountCurrency = $this->str($account, 'currency');
			if ($accountCurrency !== '') {
				return $accountCurrency;
			}
		}

		return 'EUR';
	}//end accountsCurrency()

	/**
	 * The account's join key (accountNumber preferred, then code/rgsCode/id).
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return string
	 */
	protected function accountKey(array $account): string {
		return $this->str($account, 'accountNumber', 'code', 'rgsCode', 'id');
	}//end accountKey()

	/**
	 * A display label for an account row: "<number> <name>".
	 *
	 * @param array<string, mixed> $account The Account object.
	 *
	 * @return string
	 */
	protected function accountLabel(array $account): string {
		$number = $this->str($account, 'accountNumber', 'code', 'rgsCode');
		$name = $this->str($account, 'name', 'accountName', 'title', 'description');
		$label = trim($number . ' ' . $name);
		return $label !== '' ? $label : ('Rekening ' . $this->str($account, 'id'));
	}//end accountLabel()

	/**
	 * Sum the amounts in a set of lines.
	 *
	 * @param array<int, array{label: string, amount: float}> $lines The lines.
	 *
	 * @return float
	 */
	protected function total(array $lines): float {
		$sum = 0.0;
		foreach ($lines as $row) {
			$sum += (float)($row['amount'] ?? 0.0);
		}

		return $sum;
	}//end total()

	/**
	 * Add an account table — the account lines and their bold total — and
	 * answer the total.
	 *
	 * @param StatementRun  $run    The section and currency being rendered.
	 * @param AccountBucket $bucket The account lines and their total label.
	 *
	 * @return float The total of the lines.
	 */
	protected function addAccountTable(StatementRun $run, AccountBucket $bucket): float {
		$total = $this->total($bucket->lines);
		$this->addAmountTable(
			$run->section,
			'Rekening',
			$this->accountAmountHead(),
			$bucket->lines,
			['label' => $bucket->totalLabel, 'amount' => $total],
			$run->currency
		);

		return $total;
	}//end addAccountTable()

	/**
	 * Load a schema scoped to the administration, falling back to every row
	 * when the scoped read finds none (some installs do not scope by
	 * administration).
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration scope (may be empty).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadScoped(string $schema, string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$rows = $this->loadObjects($schema, $filters);
		if ($rows === [] && $filters !== []) {
			$rows = $this->loadObjects($schema);
		}

		return $rows;
	}//end loadScoped()

	/**
	 * Collapse the chart of accounts to one row per (administration, account key)
	 * so an unscoped read does not list or count the same general-ledger account
	 * twice. The first occurrence wins.
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
}//end class
