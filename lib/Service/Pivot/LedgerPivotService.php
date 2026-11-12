<?php

/**
 * Ledger Pivot Service
 *
 * Sums the result of posted ledger lines over two chosen axes
 * (reporting-custom-analysis REQ-RCA-002). A line counts when its account is
 * a profit and loss account and its transaction is posted and not reversed,
 * the two stamps reporting-segment-results writes, so the pivot and the
 * segment dashboard add up the same lines. Revenue is positive, costs
 * negative, because the pivot sums `signedAmount`.
 *
 * The date range is the transaction's posting date. The customer of a line
 * is the customer on the receivables line of the same transaction, which is
 * how a revenue line, which names no customer itself, is attributed.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pivot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pivot;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\RegisterLocation;

/**
 * Pivots posted result lines on two axes.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
class LedgerPivotService {
	use ReadsSourceRowsInBatches;

	/**
	 * The axes a pivot may use.
	 */
	public const AXES = ['account', 'accountGroup', 'period', 'quarter', 'costCenter', 'project', 'customer'];

	/**
	 * The most groups one axis shows.
	 */
	public const MAX_GROUPS = 200;

	/**
	 * The longest date range, in months.
	 */
	public const MAX_MONTHS = 36;

	/**
	 * The most ledger lines one pivot reads.
	 */
	public const MAX_LINES = 50000;

	/**
	 * Rows per read.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Whether the last read stopped at MAX_LINES.
	 *
	 * @var bool
	 */
	private bool $truncated = false;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param RegisterLocation        $registerLocation Where the register lives (slug + OpenRegister availability).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {

	}//end __construct()

	/**
	 * Pivot one administration's result lines.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $rowAxis          One of AXES.
	 * @param string $columnAxis       One of AXES, different from the row axis.
	 * @param string $from             First posting date, YYYY-MM-DD.
	 * @param string $to               Last posting date, YYYY-MM-DD.
	 *
	 * @return array<string, mixed> rows, columns, cells, rowTotals, columnTotals, total, capped, truncated.
	 *
	 * @throws InvalidArgumentException When an axis, a date or the range is not accepted.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function pivot(string $administrationId, string $rowAxis, string $columnAxis, string $from, string $to): array {
		$run       = new LedgerPivotRun(rowAxis: $rowAxis, columnAxis: $columnAxis);
		$run->from = $from;
		$run->to   = $to;

		$this->assertRequest(run: $run);
		$this->truncated = false;

		$this->loadLookups(administrationId: $administrationId, run: $run);
		$this->sumCells(administrationId: $administrationId, run: $run);

		return $this->shape(run: $run);

	}//end pivot()

	/**
	 * Refuse an axis outside the vocabulary, a malformed date or a range too long.
	 *
	 * @param LedgerPivotRun $run The request.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the request is not accepted.
	 */
	private function assertRequest(LedgerPivotRun $run): void {
		foreach ([$run->rowAxis, $run->columnAxis] as $axis) {
			if (in_array($axis, self::AXES, true) === false) {
				throw new InvalidArgumentException('Unknown pivot axis: ' . $axis);
			}
		}

		if ($run->rowAxis === $run->columnAxis) {
			throw new InvalidArgumentException('Rows and columns need two different axes');
		}

		$this->assertRange(run: $run);

	}//end assertRequest()

	/**
	 * Refuse a malformed date, a range that ends before it starts, or one too long.
	 *
	 * @param LedgerPivotRun $run The request.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the range is not accepted.
	 */
	private function assertRange(LedgerPivotRun $run): void {
		$start = DateTimeImmutable::createFromFormat('!Y-m-d', $run->from);
		$end   = DateTimeImmutable::createFromFormat('!Y-m-d', $run->to);
		if ($this->readsAs(date: $start, text: $run->from) === false || $this->readsAs(date: $end, text: $run->to) === false) {
			throw new InvalidArgumentException('Dates must read YYYY-MM-DD');
		}

		if ($end < $start) {
			throw new InvalidArgumentException('The range ends before it starts');
		}

		if ($this->monthsSpanned(start: $start, end: $end) > self::MAX_MONTHS) {
			throw new InvalidArgumentException('The range may span at most ' . self::MAX_MONTHS . ' months');
		}

	}//end assertRange()

	/**
	 * Whether a parsed date reads back exactly as the text it came from.
	 *
	 * @param DateTimeImmutable|false $date The parsed date, false when it did not parse.
	 * @param string                  $text The requested date.
	 *
	 * @return bool
	 */
	private function readsAs(DateTimeImmutable|false $date, string $text): bool {
		return $date !== false && $date->format('Y-m-d') === $text;

	}//end readsAs()

	/**
	 * The calendar months a range touches, both ends included.
	 *
	 * @param DateTimeImmutable $start First date.
	 * @param DateTimeImmutable $end   Last date.
	 *
	 * @return int
	 */
	private function monthsSpanned(DateTimeImmutable $start, DateTimeImmutable $end): int {
		return ((((int)$end->format('Y') - (int)$start->format('Y')) * 12) + ((int)$end->format('n') - (int)$start->format('n')) + 1);

	}//end monthsSpanned()

	/**
	 * Load the posted transactions in range, the labels and, for a customer axis, the customers.
	 *
	 * @param string         $administrationId The administration.
	 * @param LedgerPivotRun $run              The pivot in progress.
	 *
	 * @return void
	 */
	private function loadLookups(string $administrationId, LedgerPivotRun $run): void {
		$run->transactions = $this->postedTransactions(administrationId: $administrationId, run: $run);
		$run->labels       = $this->labels(administrationId: $administrationId, axes: [$run->rowAxis, $run->columnAxis]);
		if (in_array('customer', [$run->rowAxis, $run->columnAxis], true) === true) {
			$run->customers = $this->customerPerTransaction(administrationId: $administrationId);
		}

	}//end loadLookups()

	/**
	 * Sum the signed amount of each result line of a transaction in range into its cell.
	 *
	 * @param string         $administrationId The administration.
	 * @param LedgerPivotRun $run              The pivot in progress.
	 *
	 * @return void
	 */
	private function sumCells(string $administrationId, LedgerPivotRun $run): void {
		foreach ($this->resultLines(administrationId: $administrationId) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			if (isset($run->transactions[$transactionId]) === false) {
				continue;
			}

			$context = $this->lineContext(run: $run, line: $line);
			$rowKey  = $this->key(axis: $run->rowAxis, context: $context);
			$colKey  = $this->key(axis: $run->columnAxis, context: $context);
			$run->cells[$rowKey][$colKey] = (($run->cells[$rowKey][$colKey] ?? 0.0) + (float)($line['signedAmount'] ?? 0));
		}

	}//end sumCells()

	/**
	 * What the axis keys of a line are read from: the line, its posting date,
	 * its transaction's customer and the lookups.
	 *
	 * @param LedgerPivotRun       $run  The pivot in progress.
	 * @param array<string, mixed> $line A result line of a transaction in range.
	 *
	 * @return array<string, mixed>
	 */
	private function lineContext(LedgerPivotRun $run, array $line): array {
		$transactionId = (string)($line['transactionId'] ?? '');

		return [
			'line'        => $line,
			'postingDate' => $run->transactions[$transactionId],
			'customer'    => ($run->customers[$transactionId] ?? ''),
			'labels'      => $run->labels,
		];

	}//end lineContext()

	/**
	 * Posting date per posted transaction inside the range.
	 *
	 * @param string         $administrationId The administration.
	 * @param LedgerPivotRun $run              The pivot, for its range.
	 *
	 * @return array<string, string> Transaction id => YYYY-MM-DD.
	 */
	private function postedTransactions(string $administrationId, LedgerPivotRun $run): array {
		$dates = [];
		foreach ($this->rows(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']) as $transaction) {
			$date = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($date < $run->from || $date > $run->to) {
				continue;
			}

			$id = (string)($transaction['@self']['id'] ?? $transaction['id'] ?? '');
			if ($id !== '') {
				$dates[$id] = $date;
			}
		}

		return $dates;

	}//end postedTransactions()

	/**
	 * The lines that count in a result: profit and loss, posted, not reversed.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 */
	private function resultLines(string $administrationId): array {
		return array_values(
			array_filter(
				$this->rows(schema: 'GLLine', filters: ['administrationId' => $administrationId, 'accountClass' => 'pnl']),
				static fn (array $line): bool => ($line['countsInResult'] ?? false) === true
			)
		);

	}//end resultLines()

	/**
	 * The customer of each transaction, from its receivables line.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string> Transaction id => customer id.
	 */
	private function customerPerTransaction(string $administrationId): array {
		$customers = [];
		foreach ($this->rows(schema: 'GLLine', filters: ['administrationId' => $administrationId, 'subLedgerType' => 'ar']) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			$customer      = (string)($line['subLedgerRef'] ?? '');
			if ($transactionId !== '' && $customer !== '') {
				$customers[$transactionId] = $customer;
			}
		}

		return $customers;

	}//end customerPerTransaction()

	/**
	 * Display names for the axes that need a lookup.
	 *
	 * @param string             $administrationId The administration.
	 * @param array<int, string> $axes             The two axes.
	 *
	 * @return array<string, array<string, string>> accounts (number => name), parents (number => parent number), customers (id => name).
	 */
	private function labels(string $administrationId, array $axes): array {
		$labels = ['accounts' => [], 'parents' => [], 'types' => [], 'customers' => []];
		if (array_intersect($axes, ['account', 'accountGroup']) !== []) {
			$labels = array_merge($labels, $this->accountLabels(administrationId: $administrationId));
		}

		if (in_array('customer', $axes, true) === true) {
			$labels['customers'] = $this->customerLabels(administrationId: $administrationId);
		}

		return $labels;

	}//end labels()

	/**
	 * Name, parent account and account type per account number.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array{accounts: array<string, string>, parents: array<string, string>, types: array<string, string>}
	 */
	private function accountLabels(string $administrationId): array {
		$labels = ['accounts' => [], 'parents' => [], 'types' => []];
		foreach ($this->rows(schema: 'Account', filters: ['administrationId' => $administrationId]) as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			$labels['accounts'][$number] = (string)($account['name'] ?? '');
			$labels['parents'][$number]  = (string)($account['parentAccountNumber'] ?? '');
			$labels['types'][$number]    = (string)($account['accountType'] ?? '');
		}

		return $labels;

	}//end accountLabels()

	/**
	 * The customer name under each of its ids (object id, id, customerId).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string>
	 */
	private function customerLabels(string $administrationId): array {
		$names = [];
		foreach ($this->rows(schema: 'CustomerMaster', filters: ['administrationId' => $administrationId]) as $customer) {
			$name = $this->customerName(customer: $customer);
			foreach ([($customer['@self']['id'] ?? null), ($customer['id'] ?? null), ($customer['customerId'] ?? null)] as $id) {
				if (is_string($id) === true && $id !== '') {
					$names[$id] = $name;
				}
			}
		}

		return $names;

	}//end customerLabels()

	/**
	 * A customer's legal name, else its trade name.
	 *
	 * @param array<string, mixed> $customer The CustomerMaster record.
	 *
	 * @return string
	 */
	private function customerName(array $customer): string {
		$name = (string)($customer['legalName'] ?? '');
		if ($name === '') {
			$name = (string)($customer['tradeName'] ?? '');
		}

		return $name;

	}//end customerName()

	/**
	 * The group key of one line on one axis; an empty string when the line has no value.
	 *
	 * @param string               $axis    The axis.
	 * @param array<string, mixed> $context line, postingDate, customer, labels.
	 *
	 * @return string The key.
	 */
	private function key(string $axis, array $context): string {
		$line = $context['line'];
		$date = (string)$context['postingDate'];

		return match ($axis) {
			'account'      => (string)($line['accountNumber'] ?? ''),
			'accountGroup' => $this->groupKey(accountNumber: (string)($line['accountNumber'] ?? ''), labels: $context['labels']),
			'period'       => substr($date, 0, 7),
			'quarter'      => substr($date, 0, 4) . '-Q' . (intdiv(((int)substr($date, 5, 2) - 1), 3) + 1),
			'costCenter'   => (string)($line['costCenterCode'] ?? $line['costCenter'] ?? ''),
			'project'      => (string)($line['projectCode'] ?? ''),
			default        => (string)$context['customer'],
		};

	}//end key()

	/**
	 * An account's group: its parent account, or its account type when it has no parent.
	 *
	 * @param string                               $accountNumber The account.
	 * @param array<string, array<string, string>> $labels        The lookups.
	 *
	 * @return string The group key.
	 */
	private function groupKey(string $accountNumber, array $labels): string {
		$parent = ($labels['parents'][$accountNumber] ?? '');
		if ($parent !== '') {
			return $parent;
		}

		$type = ($labels['types'][$accountNumber] ?? '');
		if ($type === '') {
			return '';
		}

		return 'type:' . $type;

	}//end groupKey()

	/**
	 * Turn the cell sums into the response: capped, ordered and labelled axes with totals.
	 *
	 * @param LedgerPivotRun $run The pivot, with its cell sums.
	 *
	 * @return array<string, mixed> The pivot.
	 */
	private function shape(LedgerPivotRun $run): array {
		$this->sumTotals(run: $run);
		$run->rowKeys    = $this->keep(totals: $run->rowTotals, axis: $run->rowAxis);
		$run->columnKeys = $this->keep(totals: $run->columnTotals, axis: $run->columnAxis);

		$labels = new PivotLabels(labels: $run->labels);

		return [
			'rowAxis'      => $run->rowAxis,
			'columnAxis'   => $run->columnAxis,
			'rows'         => $labels->describe(keys: $run->rowKeys, axis: $run->rowAxis),
			'columns'      => $labels->describe(keys: $run->columnKeys, axis: $run->columnAxis),
			'cells'        => $this->shownCells(run: $run),
			'rowTotals'    => $this->shownTotals(totals: $run->rowTotals, keys: $run->rowKeys),
			'columnTotals' => $this->shownTotals(totals: $run->columnTotals, keys: $run->columnKeys),
			'total'        => round(array_sum($run->rowTotals), 2),
			'capped'       => [
				'rows'    => count($run->rowTotals) > count($run->rowKeys),
				'columns' => count($run->columnTotals) > count($run->columnKeys),
			],
			'truncated'    => $this->truncated,
		];

	}//end shape()

	/**
	 * Sum each row and each column of the cells.
	 *
	 * @param LedgerPivotRun $run The pivot, with its cell sums.
	 *
	 * @return void
	 */
	private function sumTotals(LedgerPivotRun $run): void {
		foreach ($run->cells as $rowKey => $row) {
			foreach ($row as $colKey => $amount) {
				$run->rowTotals[$rowKey]    = (($run->rowTotals[$rowKey] ?? 0.0) + $amount);
				$run->columnTotals[$colKey] = (($run->columnTotals[$colKey] ?? 0.0) + $amount);
			}
		}

	}//end sumTotals()

	/**
	 * The cells of the kept rows and columns, rounded to cents.
	 *
	 * @param LedgerPivotRun $run The pivot, with its kept keys.
	 *
	 * @return array<string, array<string, float>>
	 */
	private function shownCells(LedgerPivotRun $run): array {
		$shownCells = [];
		foreach ($run->rowKeys as $rowKey) {
			foreach ($run->columnKeys as $colKey) {
				if (isset($run->cells[$rowKey][$colKey]) === true) {
					$shownCells[$rowKey][$colKey] = round($run->cells[$rowKey][$colKey], 2);
				}
			}
		}

		return $shownCells;

	}//end shownCells()

	/**
	 * The totals of the kept keys, rounded to cents.
	 *
	 * @param array<string, float> $totals Key => total.
	 * @param array<int, string>   $keys   The kept keys.
	 *
	 * @return array<string, float>
	 */
	private function shownTotals(array $totals, array $keys): array {
		return array_map(static fn (float $sum): float => round($sum, 2), array_intersect_key($totals, array_flip($keys)));

	}//end shownTotals()

	/**
	 * The keys an axis shows: the largest MAX_GROUPS by size, in the axis's natural order.
	 *
	 * @param array<string, float> $totals Key => total.
	 * @param string               $axis   The axis.
	 *
	 * @return array<int, string> The kept keys.
	 */
	private function keep(array $totals, string $axis): array {
		$keys = array_map('strval', array_keys($totals));
		if (count($keys) > self::MAX_GROUPS) {
			usort($keys, static fn (string $a, string $b): int => (abs($totals[$b]) <=> abs($totals[$a])));
			$keys = array_slice($keys, 0, self::MAX_GROUPS);
		}

		sort($keys, SORT_STRING);
		if (in_array($axis, ['period', 'quarter'], true) === false && in_array('', $keys, true) === true) {
			// The lines without a value close the list.
			$keys   = array_values(array_diff($keys, ['']));
			$keys[] = '';
		}

		return $keys;

	}//end keep()

	/**
	 * Rows of a schema in pages, up to MAX_LINES; sets the truncated flag when it stops early.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The rows as payload arrays.
	 */
	private function rows(string $schema, array $filters): array {
		$rows   = [];
		$offset = 0;
		do {
			$page = $this->objectService
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::PAGE_SIZE, 'offset' => $offset, 'filters' => $filters]);
			foreach ($page as $row) {
				// The serialised form carries @self.id, which a line's transactionId names.
				if ($row instanceof JsonSerializable) {
					$row = $row->jsonSerialize();
				}

				$rows[] = $this->rowPayload(row: $row);
			}

			$offset += self::PAGE_SIZE;
			if (count($rows) >= self::MAX_LINES) {
				$this->truncated = true;
				return array_slice($rows, 0, self::MAX_LINES);
			}

			$fullPage = (count($page) >= self::PAGE_SIZE);
		} while ($fullPage === true);

		return $rows;

	}//end rows()
}//end class
