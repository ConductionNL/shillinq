<?php

/**
 * Profit and loss and balance sheet figures from posted ledger lines.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Throwable;

/**
 * The one source of statement figures for the pages and the documents.
 *
 * It reads the `GLLine` records whose `countsInResult` stamp is true (posted,
 * not reversed), dates each by its transaction's `postingDate`, and sums
 * `signedAmount` (credit positive) per account. Stored account balances are
 * never read. `resultsBetween` covers the profit and loss accounts of a date
 * range; `balancesAsOf` covers the balance accounts up to a date and adds the
 * running result of the fiscal year to equity, so the sheet balances.
 *
 * Reads are cached per administration for the life of the request, so a
 * comparison column or a second statement does not read the ledger again.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
class StatementCalculator {

	/**
	 * Rows read per page.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The equity line carrying the result of the fiscal year so far.
	 */
	public const RUNNING_RESULT_LABEL = 'Resultaat lopend boekjaar';

	/**
	 * The equity line carrying results of earlier years not yet closed.
	 */
	public const EARLIER_RESULT_LABEL = 'Onverdeeld resultaat voorgaande boekjaren';

	/**
	 * Account types read as revenue or expense (Dutch accepted on read).
	 */
	private const PNL_TYPES = [
		'revenue' => 'revenue',
		'income' => 'revenue',
		'opbrengsten' => 'revenue',
		'baten' => 'revenue',
		'expenses' => 'expense',
		'expense' => 'expense',
		'kosten' => 'expense',
		'lasten' => 'expense',
	];

	/**
	 * Account types read as assets, liabilities or equity (Dutch accepted on read).
	 */
	private const BALANCE_TYPES = [
		'assets' => 'assets',
		'asset' => 'assets',
		'activa' => 'assets',
		'liabilities' => 'liabilities',
		'liability' => 'liabilities',
		'debts' => 'liabilities',
		'vreemd-vermogen' => 'liabilities',
		'equity' => 'equity',
		'eigen-vermogen' => 'equity',
	];

	/**
	 * Counting lines per administration: accountNumber, class, date, amount.
	 *
	 * @var array<string, array<int, array{account: string, class: string, date: string, amount: float}>>
	 */
	private array $lines = [];

	/**
	 * Accounts per administration: accountNumber => row.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $accounts = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface   $objectService    OpenRegister object service.
	 * @param RegisterLocation         $registerLocation Where the register lives.
	 * @param FiscalYearContextService $fiscalYears      Fiscal year windows of an administration.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly FiscalYearContextService $fiscalYears,
	) {
	}//end __construct()

	/**
	 * The profit and loss over a date range, both dates inclusive.
	 *
	 * Revenue is shown credit positive, expenses debit positive, and the
	 * result is revenue minus expenses. An account whose type is neither
	 * revenue nor expense goes where its balance puts it.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $from             First posting date, YYYY-MM-DD.
	 * @param string $to               Last posting date, YYYY-MM-DD.
	 *
	 * @return array<string, mixed> from, to, revenue, expense (rows of accountNumber, label, amount, from, to) and totals.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public function resultsBetween(string $administrationId, string $from, string $to): array {
		$sums = $this->sumPerAccount(administrationId: $administrationId, class: 'pnl', from: $from, to: $to);

		$sections = ['revenue' => [], 'expense' => []];
		foreach ($sums as $number => $signed) {
			// Numeric account numbers come back from array keys as int.
			$account = (string)$number;
			$type = $this->accountType(administrationId: $administrationId, account: $account);
			$section = $this->section(types: self::PNL_TYPES, type: $type, bySign: ['revenue', 'expense'], signed: $signed);
			$amount = $signed;
			if ($section === 'expense') {
				$amount = -$signed;
			}

			$sections[$section][] = $this->row(administrationId: $administrationId, account: $account, amount: $amount, from: $from, to: $to);
		}

		$revenue = $this->total(rows: $sections['revenue']);
		$expense = $this->total(rows: $sections['expense']);

		return [
			'administrationId' => $administrationId,
			'from' => $from,
			'to' => $to,
			'revenue' => $sections['revenue'],
			'expense' => $sections['expense'],
			'totals' => [
				'revenue' => $revenue,
				'expense' => $expense,
				'result' => self::round(value: ($revenue - $expense)),
			],
		];
	}//end resultsBetween()

	/**
	 * The balance sheet at the end of a date.
	 *
	 * Balance accounts carry every counting line up to and including the
	 * date. Equity gains a "Resultaat lopend boekjaar" line with the profit
	 * and loss from the start of the fiscal year to the date; it is left out
	 * only when that year is closed and its closing entries have already
	 * brought the result to zero. Profit and loss left over from earlier
	 * years that were never closed shows as its own equity line, so assets
	 * always equal liabilities plus equity.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $asOf             The date, YYYY-MM-DD.
	 *
	 * @return array<string, mixed> asOf, fiscalYearStart, yearClosed, assets, liabilities, equity and totals.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public function balancesAsOf(string $administrationId, string $asOf): array {
		$yearStart = $this->fiscalYearStart(administrationId: $administrationId, asOf: $asOf);
		$sums = $this->sumPerAccount(administrationId: $administrationId, class: 'balance', from: '', to: $asOf);

		$sections = ['assets' => [], 'liabilities' => [], 'equity' => []];
		foreach ($sums as $number => $signed) {
			// Numeric account numbers come back from array keys as int.
			$account = (string)$number;
			$type = $this->accountType(administrationId: $administrationId, account: $account);
			$section = $this->section(types: self::BALANCE_TYPES, type: $type, bySign: ['liabilities', 'assets'], signed: $signed);
			$amount = $signed;
			if ($section === 'assets') {
				$amount = -$signed;
			}

			$sections[$section][] = $this->row(administrationId: $administrationId, account: $account, amount: $amount, from: $yearStart, to: $asOf);
		}

		$yearClosed = $this->yearClosed(administrationId: $administrationId, asOf: $asOf);
		$earlier = $this->resultTotal(administrationId: $administrationId, from: '', to: self::dayBefore(date: $yearStart));
		if (abs($earlier) >= 0.005) {
			$sections['equity'][] = ['accountNumber' => null, 'label' => self::EARLIER_RESULT_LABEL, 'amount' => $earlier, 'from' => null, 'to' => null];
		}

		$running = $this->resultTotal(administrationId: $administrationId, from: $yearStart, to: $asOf);
		if ($yearClosed === false || abs($running) >= 0.005) {
			$sections['equity'][] = [
				'accountNumber' => null,
				'label' => self::RUNNING_RESULT_LABEL,
				'amount' => $running,
				'from' => $yearStart,
				'to' => $asOf,
			];
		}

		$assets = $this->total(rows: $sections['assets']);
		$liabilities = $this->total(rows: $sections['liabilities']);
		$equity = $this->total(rows: $sections['equity']);

		return [
			'administrationId' => $administrationId,
			'asOf' => $asOf,
			'fiscalYearStart' => $yearStart,
			'yearClosed' => $yearClosed,
			'assets' => $sections['assets'],
			'liabilities' => $sections['liabilities'],
			'equity' => $sections['equity'],
			'totals' => [
				'assets' => $assets,
				'liabilities' => $liabilities,
				'equity' => $equity,
				'liabilitiesAndEquity' => self::round(value: ($liabilities + $equity)),
				'difference' => self::round(value: ($assets - $liabilities - $equity)),
			],
		];
	}//end balancesAsOf()

	/**
	 * The first day of the fiscal year the date falls in.
	 *
	 * Read from the administration's fiscal year start; 1 January of the
	 * date's year when the administration cannot be read.
	 *
	 * @param string $administrationId The administration.
	 * @param string $asOf             The date, YYYY-MM-DD.
	 *
	 * @return string YYYY-MM-DD.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public function fiscalYearStart(string $administrationId, string $asOf): string {
		$fallback = substr($asOf, 0, 4) . '-01-01';
		$administration = $this->administration(administrationId: $administrationId);
		if ($administration === null) {
			return $fallback;
		}

		try {
			$reference = new DateTimeImmutable($asOf, new DateTimeZone('UTC'));
			$year = $this->fiscalYears->fiscalYearFor(administration: $administration, now: $reference);
			$window = $this->fiscalYears->windowForFiscalYear(administration: $administration, fiscalYear: $year);
		} catch (Throwable $e) {
			return $fallback;
		}

		return (string)($window['startDate'] ?? $fallback);
	}//end fiscalYearStart()

	/**
	 * The signed sum per account of one class within a range.
	 *
	 * @param string $administrationId The administration.
	 * @param string $class            pnl or balance.
	 * @param string $from             First date, inclusive; empty for no lower bound.
	 * @param string $to               Last date, inclusive.
	 *
	 * @return array<string, float> accountNumber => signed sum, sorted by account number.
	 */
	private function sumPerAccount(string $administrationId, string $class, string $from, string $to): array {
		$sums = [];
		foreach ($this->countingLines(administrationId: $administrationId) as $line) {
			if ($line['class'] !== $class || $line['date'] > $to || ($from !== '' && $line['date'] < $from)) {
				continue;
			}

			$sums[$line['account']] = (($sums[$line['account']] ?? 0.0) + $line['amount']);
		}

		ksort($sums, SORT_STRING);

		return array_filter($sums, static fn (float $sum): bool => abs($sum) >= 0.005);
	}//end sumPerAccount()

	/**
	 * The result (credit positive) of the profit and loss lines in a range.
	 *
	 * @param string $administrationId The administration.
	 * @param string $from             First date, inclusive; empty for no lower bound.
	 * @param string $to               Last date, inclusive.
	 *
	 * @return float The result.
	 */
	private function resultTotal(string $administrationId, string $from, string $to): float {
		return self::round(value: array_sum($this->sumPerAccount(administrationId: $administrationId, class: 'pnl', from: $from, to: $to)));
	}//end resultTotal()

	/**
	 * Whether a closed fiscal year of the administration contains the date.
	 *
	 * @param string $administrationId The administration.
	 * @param string $asOf             The date.
	 *
	 * @return bool True when that year is closed.
	 */
	private function yearClosed(string $administrationId, string $asOf): bool {
		foreach ($this->read(schema: 'FiscalYear', filters: ['administrationId' => $administrationId]) as $year) {
			$start = substr((string)($year['startDate'] ?? ''), 0, 10);
			$end = substr((string)($year['endDate'] ?? ''), 0, 10);
			if ($start !== '' && $end !== '' && $start <= $asOf && $asOf <= $end) {
				return ($year['state'] ?? '') === 'closed';
			}
		}

		return false;
	}//end yearClosed()

	/**
	 * The lines that count, dated by their posted transaction (cached).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array{account: string, class: string, date: string, amount: float}>
	 */
	private function countingLines(string $administrationId): array {
		if (isset($this->lines[$administrationId]) === true) {
			return $this->lines[$administrationId];
		}

		$dates = $this->postingDates(administrationId: $administrationId);
		$lines = [];
		foreach ($this->read(schema: 'GLLine', filters: ['administrationId' => $administrationId]) as $line) {
			$date = ($dates[(string)($line['transactionId'] ?? '')] ?? null);
			$account = trim((string)($line['accountNumber'] ?? ''));
			if (($line['countsInResult'] ?? null) !== true || $date === null || $account === '') {
				continue;
			}

			$lines[] = [
				'account' => $account,
				'class' => $this->lineClass(administrationId: $administrationId, line: $line, account: $account),
				'date' => $date,
				'amount' => self::signedAmount(line: $line),
			];
		}

		$this->lines[$administrationId] = $lines;

		return $lines;
	}//end countingLines()

	/**
	 * Posting date per posted transaction of the administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string> Transaction id => YYYY-MM-DD.
	 */
	private function postingDates(string $administrationId): array {
		$dates = [];
		foreach ($this->read(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']) as $transaction) {
			$id = (string)($transaction['@self']['id'] ?? $transaction['id'] ?? '');
			$date = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($id !== '' && $date !== '') {
				$dates[$id] = $date;
			}
		}

		return $dates;
	}//end postingDates()

	/**
	 * The line's class as stamped; from its account's type when unstamped.
	 *
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $line             The line.
	 * @param string               $account          Its account number.
	 *
	 * @return string pnl or balance.
	 */
	private function lineClass(string $administrationId, array $line, string $account): string {
		$stamped = ($line['accountClass'] ?? null);
		if ($stamped === 'pnl' || $stamped === 'balance') {
			return $stamped;
		}

		if (isset(self::PNL_TYPES[$this->accountType(administrationId: $administrationId, account: $account)]) === true) {
			return 'pnl';
		}

		return 'balance';
	}//end lineClass()

	/**
	 * The statement section of an account: by its type, else by its sign.
	 *
	 * @param array<string, string>      $types  Account type => section.
	 * @param string                     $type   The account's type.
	 * @param array{0: string, 1: string} $bySign Section for a credit balance, for a debit balance.
	 * @param float                      $signed The account's signed sum.
	 *
	 * @return string The section.
	 */
	private function section(array $types, string $type, array $bySign, float $signed): string {
		if (isset($types[$type]) === true) {
			return $types[$type];
		}

		if ($signed >= 0.0) {
			return $bySign[0];
		}

		return $bySign[1];
	}//end section()

	/**
	 * The line's amount, credit positive; from side and amount when not materialised.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return float The signed amount.
	 */
	private static function signedAmount(array $line): float {
		if (is_numeric($line['signedAmount'] ?? null) === true) {
			return (float)$line['signedAmount'];
		}

		$amount = 0.0;
		if (is_numeric($line['amount'] ?? null) === true) {
			$amount = (float)$line['amount'];
		}

		if (($line['side'] ?? '') === 'credit') {
			return $amount;
		}

		return -$amount;
	}//end signedAmount()

	/**
	 * One statement row.
	 *
	 * @param string $administrationId The administration.
	 * @param string $account          The account number.
	 * @param float  $amount           The amount as shown.
	 * @param string $from             Start of the range behind the figure.
	 * @param string $to               End of the range behind the figure.
	 *
	 * @return array<string, mixed> accountNumber, label, amount, from, to.
	 */
	private function row(string $administrationId, string $account, float $amount, string $from, string $to): array {
		$name = trim((string)($this->accountRows(administrationId: $administrationId)[$account]['name'] ?? ''));

		return [
			'accountNumber' => $account,
			'label' => trim($account . ' ' . $name),
			'amount' => self::round(value: $amount),
			'from' => $from,
			'to' => $to,
		];
	}//end row()

	/**
	 * The lower-cased type of an account, or an empty string.
	 *
	 * @param string $administrationId The administration.
	 * @param string $account          The account number.
	 *
	 * @return string The type.
	 */
	private function accountType(string $administrationId, string $account): string {
		$row = ($this->accountRows(administrationId: $administrationId)[$account] ?? []);

		return strtolower(trim((string)($row['accountType'] ?? $row['type'] ?? '')));
	}//end accountType()

	/**
	 * The administration's accounts by number (cached).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function accountRows(string $administrationId): array {
		if (isset($this->accounts[$administrationId]) === false) {
			$byNumber = [];
			foreach ($this->read(schema: 'Account', filters: ['administrationId' => $administrationId]) as $account) {
				$number = trim((string)($account['accountNumber'] ?? ''));
				if ($number !== '' && isset($byNumber[$number]) === false) {
					$byNumber[$number] = $account;
				}
			}

			$this->accounts[$administrationId] = $byNumber;
		}

		return $this->accounts[$administrationId];
	}//end accountRows()

	/**
	 * The administration record, by id or administration code.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The record, or null when it cannot be read.
	 */
	private function administration(string $administrationId): ?array {
		try {
			$scoped = $this->objectService
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema('Administration');

			return ObjectIdentifier::findOne(scoped: $scoped, id: $administrationId, fallbackProperty: 'administrationCode');
		} catch (Throwable $e) {
			return null;
		}
	}//end administration()

	/**
	 * Every row of a schema matching the filters, as payload arrays, read in
	 * pages under the caller's own access rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		$rows = [];
		$offset = 0;
		do {
			$page = $this->objectService
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::PAGE_SIZE, 'offset' => $offset, 'filters' => $filters]);
			foreach ($page as $row) {
				if ($row instanceof JsonSerializable) {
					$row = $row->jsonSerialize();
				}

				if (is_array($row) === true) {
					$rows[] = $row;
				}
			}

			$offset += self::PAGE_SIZE;
		} while (count($page) >= self::PAGE_SIZE);

		return $rows;
	}//end read()

	/**
	 * The rounded sum of the rows' amounts.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return float The total.
	 */
	private function total(array $rows): float {
		return self::round(value: array_sum(array_column($rows, 'amount')));
	}//end total()

	/**
	 * The day before a date.
	 *
	 * @param string $date YYYY-MM-DD.
	 *
	 * @return string YYYY-MM-DD.
	 */
	private static function dayBefore(string $date): string {
		return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
	}//end dayBefore()

	/**
	 * Round to cents, without negative zero.
	 *
	 * @param float $value The value.
	 *
	 * @return float The rounded value.
	 */
	private static function round(float $value): float {
		$rounded = round($value, 2);
		if (abs($rounded) < 0.005) {
			return 0.0;
		}

		return $rounded;
	}//end round()
}//end class
