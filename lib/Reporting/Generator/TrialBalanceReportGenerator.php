<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Trial-balance data-report generator
 *
 * Renders the proef- en saldibalans (trial balance) natively as CSV. It loads the
 * GLLine rows from OpenRegister (optionally scoped to the context administration
 * and period), sums debit/credit movement per accountNumber, joins the account name
 * from the Account schema, and emits one CSV row per account with the closing
 * balance. The rendering is byte-native (fputcsv into php://temp) — no office or
 * spreadsheet library is involved.
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
 *       NOTE: this generator is one of the three that CONTRADICT their domain
 *       capability (REQ-TB-001 — "not a PHP report builder"), so retagging it at
 *       bookkeeping-trial-balance would make the gate pass against a requirement
 *       the code breaks. That contradiction is the substance of #525.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. Note in
 * particular that bookkeeping-trial-balance REQ-TB-001 forbids a PHP report
 * builder for the trial balance outright, which is what this class is — see
 * shillinq#500; pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.Commenting.InlineComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Trial-balance CSV generator built natively from GLLine + Account.
 */
final class TrialBalanceReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * Construct the trial-balance report generator.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'trial-balance';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['csv'];
	}//end supportedFormats()

	/**
	 * Render the trial balance for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'csv'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$filters = $this->lineFilters($context);
		$lines = $this->loadAll('GLLine', $filters);
		$accounts = $this->indexAccountsByNumber($this->loadAll('Account', $this->administrationFilter($context)));

		return new GeneratedFile(
			fileName: $this->fileName('trial-balance', $context, 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: $this->csvContent(rows: $this->balanceRows(totals: $this->totalsByAccount(lines: $lines, accounts: $accounts))),
		);

	}//end generate()

	/**
	 * Debit and credit totals per account (eliminated and unassigned lines
	 * left out), accounts in key order; the name comes from the Account,
	 * else the first line's accountName.
	 *
	 * @param list<array<string, mixed>>          $lines    The GL lines.
	 * @param array<string, array<string, mixed>> $accounts Accounts by number.
	 *
	 * @return array<string, array{name: string, debit: float, credit: float}> accountNumber => totals.
	 */
	private function totalsByAccount(array $lines, array $accounts): array {
		$byAccount = [];
		foreach ($lines as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if (($line['eliminationFlag'] ?? false) === true || $accountNumber === '') {
				continue;
			}

			$byAccount[$accountNumber] ??= [
				'name' => (string)($accounts[$accountNumber]['name'] ?? $line['accountName'] ?? ''),
				'debit' => 0.0,
				'credit' => 0.0,
			];
			$side = $this->sideOf(line: $line);
			$byAccount[$accountNumber][$side] += $this->toFloat($line['amount'] ?? 0);
		}

		ksort($byAccount);

		return $byAccount;
	}//end totalsByAccount()

	/**
	 * The side a line books on: `debit`, anything else counts as `credit`.
	 *
	 * @param array<string, mixed> $line The GL line.
	 *
	 * @return string debit or credit.
	 */
	private function sideOf(array $line): string {
		if ((string)($line['side'] ?? '') === 'debit') {
			return 'debit';
		}

		return 'credit';
	}//end sideOf()

	/**
	 * The CSV rows: header, one row per account with its balance, and a
	 * footing row so the balance is self-checking.
	 *
	 * @param array<string, array{name: string, debit: float, credit: float}> $totals Totals per account.
	 *
	 * @return list<list<string>> The rows.
	 */
	private function balanceRows(array $totals): array {
		$rows = [['accountNumber', 'accountName', 'debit', 'credit', 'balance']];
		$totalDebit = 0.0;
		$totalCredit = 0.0;
		foreach ($totals as $accountNumber => $row) {
			$totalDebit += $row['debit'];
			$totalCredit += $row['credit'];
			$rows[] = [
				$accountNumber,
				$row['name'],
				$this->money($row['debit']),
				$this->money($row['credit']),
				$this->money(($row['debit'] - $row['credit'])),
			];
		}

		$rows[] = ['TOTAL', '', $this->money($totalDebit), $this->money($totalCredit), $this->money(($totalDebit - $totalCredit))];

		return $rows;
	}//end balanceRows()
}//end class
