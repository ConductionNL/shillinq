<?php

/**
 * General-ledger (grootboekkaarten) data-report generator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Renders the general ledger natively as CSV: every GLLine row, grouped (sorted) by
 * accountNumber, with the running per-account balance carried down each card. The
 * account name is joined from the Account schema. Rendering is byte-native
 * (fputcsv into php://temp) — no spreadsheet library is used.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * General-ledger CSV generator built natively from GLLine + Account.
 */
final class GeneralLedgerReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * The CSV header row.
	 *
	 * @var list<string>
	 */
	private const HEADER = [
		'accountNumber',
		'accountName',
		'lineNumber',
		'transactionId',
		'date',
		'description',
		'debit',
		'credit',
		'runningBalance',
	];

	/**
	 * Construct the general-ledger report generator.
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
		return 'general-ledger';
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
	 * Render the general ledger for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'csv'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$lines = $this->loadAll('GLLine', $this->lineFilters($context));
		$accounts = $this->indexAccountsByNumber($this->loadAll('Account', $this->administrationFilter($context)));

		$rows = [self::HEADER];
		foreach ($this->linesByAccount(lines: $lines) as $accountNumber => $accountLines) {
			$card = ['accountNumber' => (string)$accountNumber, 'accountName' => (string)($accounts[$accountNumber]['name'] ?? '')];
			array_push($rows, ...$this->cardRows(card: $card, lines: $accountLines));
		}

		return new GeneratedFile(
			fileName: $this->fileName('general-ledger', $context, 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: $this->csvContent(rows: $rows),
		);

	}//end generate()

	/**
	 * Group lines by accountNumber (`(unassigned)` when empty), accounts in
	 * key order.
	 *
	 * @param list<array<string, mixed>> $lines The GL lines.
	 *
	 * @return array<string, list<array<string, mixed>>> accountNumber => lines.
	 */
	private function linesByAccount(array $lines): array {
		$byAccount = [];
		foreach ($lines as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber === '') {
				$accountNumber = '(unassigned)';
			}

			$byAccount[$accountNumber][] = $line;
		}

		ksort($byAccount);

		return $byAccount;
	}//end linesByAccount()

	/**
	 * One account card: its lines in stable order (lineNumber, then
	 * transactionId) with debit, credit and the running balance.
	 *
	 * @param array{accountNumber: string, accountName: string} $card  The account.
	 * @param list<array<string, mixed>>                       $lines The account's lines.
	 *
	 * @return list<list<string>> The CSV rows.
	 */
	private function cardRows(array $card, array $lines): array {
		usort($lines, self::compareCardLines(...));

		$rows = [];
		$running = 0.0;
		foreach ($lines as $line) {
			[$debit, $credit] = $this->debitCredit(line: $line);
			$running += ($debit - $credit);
			$rows[] = [
				$card['accountNumber'],
				$card['accountName'],
				(string)($line['lineNumber'] ?? ''),
				(string)($line['transactionId'] ?? ''),
				(string)($line['postingDate'] ?? $line['valueDate'] ?? ''),
				(string)($line['description'] ?? ''),
				$this->money($debit),
				$this->money($credit),
				$this->money($running),
			];
		}

		return $rows;
	}//end cardRows()

	/**
	 * The line's amount on its own side: [debit, credit].
	 *
	 * @param array<string, mixed> $line The GL line.
	 *
	 * @return array{0: float, 1: float} The debit and credit amounts.
	 */
	private function debitCredit(array $line): array {
		$amount = $this->toFloat($line['amount'] ?? 0);
		if ((string)($line['side'] ?? '') === 'debit') {
			return [$amount, 0.0];
		}

		return [0.0, $amount];
	}//end debitCredit()

	/**
	 * Stable order within a card: by lineNumber, then transactionId.
	 *
	 * @param array<string, mixed> $left  One line.
	 * @param array<string, mixed> $right The other line.
	 *
	 * @return int The comparison.
	 */
	private static function compareCardLines(array $left, array $right): int {
		$byLine = (((int)($left['lineNumber'] ?? 0)) <=> ((int)($right['lineNumber'] ?? 0)));
		if ($byLine !== 0) {
			return $byLine;
		}

		return strcmp((string)($left['transactionId'] ?? ''), (string)($right['transactionId'] ?? ''));
	}//end compareCardLines()
}//end class
