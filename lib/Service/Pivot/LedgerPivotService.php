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
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
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
use OCA\Shillinq\Service\SettingsService;

/**
 * Pivots posted result lines on two axes.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
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
	 * @param SettingsService        $settings      Resolves the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
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
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function pivot(string $administrationId, string $rowAxis, string $columnAxis, string $from, string $to): array {
		$this->assertRequest(rowAxis: $rowAxis, columnAxis: $columnAxis, from: $from, to: $to);
		$this->truncated = false;

		$transactions = $this->postedTransactions(administrationId: $administrationId, from: $from, to: $to);
		$labels       = $this->labels(administrationId: $administrationId, axes: [$rowAxis, $columnAxis]);
		$customers    = [];
		if (in_array('customer', [$rowAxis, $columnAxis], true) === true) {
			$customers = $this->customerPerTransaction(administrationId: $administrationId);
		}

		$cells = [];
		foreach ($this->resultLines(administrationId: $administrationId) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			if (isset($transactions[$transactionId]) === false) {
				continue;
			}

			$context = [
				'line'        => $line,
				'postingDate' => $transactions[$transactionId],
				'customer'    => ($customers[$transactionId] ?? ''),
				'labels'      => $labels,
			];
			$rowKey  = $this->key(axis: $rowAxis, context: $context);
			$colKey  = $this->key(axis: $columnAxis, context: $context);
			$cells[$rowKey][$colKey] = (($cells[$rowKey][$colKey] ?? 0.0) + (float)($line['signedAmount'] ?? 0));
		}

		return $this->shape(cells: $cells, rowAxis: $rowAxis, columnAxis: $columnAxis, labels: $labels);

	}//end pivot()

	/**
	 * Refuse an axis outside the vocabulary, a malformed date or a range too long.
	 *
	 * @param string $rowAxis    The row axis.
	 * @param string $columnAxis The column axis.
	 * @param string $from       First date.
	 * @param string $to         Last date.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the request is not accepted.
	 */
	private function assertRequest(string $rowAxis, string $columnAxis, string $from, string $to): void {
		foreach ([$rowAxis, $columnAxis] as $axis) {
			if (in_array($axis, self::AXES, true) === false) {
				throw new InvalidArgumentException('Unknown pivot axis: ' . $axis);
			}
		}

		if ($rowAxis === $columnAxis) {
			throw new InvalidArgumentException('Rows and columns need two different axes');
		}

		$start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
		$end   = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
		if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
			throw new InvalidArgumentException('Dates must read YYYY-MM-DD');
		}

		if ($end < $start) {
			throw new InvalidArgumentException('The range ends before it starts');
		}

		$months = ((((int)$end->format('Y') - (int)$start->format('Y')) * 12) + ((int)$end->format('n') - (int)$start->format('n')) + 1);
		if ($months > self::MAX_MONTHS) {
			throw new InvalidArgumentException('The range may span at most ' . self::MAX_MONTHS . ' months');
		}

	}//end assertRequest()

	/**
	 * Posting date per posted transaction inside the range.
	 *
	 * @param string $administrationId The administration.
	 * @param string $from             First date.
	 * @param string $to               Last date.
	 *
	 * @return array<string, string> Transaction id => YYYY-MM-DD.
	 */
	private function postedTransactions(string $administrationId, string $from, string $to): array {
		$dates = [];
		foreach ($this->rows(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']) as $transaction) {
			$date = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($date < $from || $date > $to) {
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
			foreach ($this->rows(schema: 'Account', filters: ['administrationId' => $administrationId]) as $account) {
				$number = (string)($account['accountNumber'] ?? '');
				$labels['accounts'][$number] = (string)($account['name'] ?? '');
				$labels['parents'][$number]  = (string)($account['parentAccountNumber'] ?? '');
				$labels['types'][$number]    = (string)($account['accountType'] ?? '');
			}
		}

		if (in_array('customer', $axes, true) === true) {
			foreach ($this->rows(schema: 'CustomerMaster', filters: ['administrationId' => $administrationId]) as $customer) {
				$name = (string)($customer['legalName'] ?? '');
				if ($name === '') {
					$name = (string)($customer['tradeName'] ?? '');
				}

				foreach ([($customer['@self']['id'] ?? null), ($customer['id'] ?? null), ($customer['customerId'] ?? null)] as $id) {
					if (is_string($id) === true && $id !== '') {
						$labels['customers'][$id] = $name;
					}
				}
			}
		}

		return $labels;

	}//end labels()

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
	 * @param array<string, array<string, float>>  $cells      Row key => column key => sum.
	 * @param string                               $rowAxis    The row axis.
	 * @param string                               $columnAxis The column axis.
	 * @param array<string, array<string, string>> $labels     The lookups.
	 *
	 * @return array<string, mixed> The pivot.
	 */
	private function shape(array $cells, string $rowAxis, string $columnAxis, array $labels): array {
		$rowTotals    = [];
		$columnTotals = [];
		foreach ($cells as $rowKey => $row) {
			foreach ($row as $colKey => $amount) {
				$rowTotals[$rowKey]    = (($rowTotals[$rowKey] ?? 0.0) + $amount);
				$columnTotals[$colKey] = (($columnTotals[$colKey] ?? 0.0) + $amount);
			}
		}

		$rowKeys    = $this->keep(totals: $rowTotals, axis: $rowAxis);
		$columnKeys = $this->keep(totals: $columnTotals, axis: $columnAxis);

		$shownCells = [];
		foreach ($rowKeys as $rowKey) {
			foreach ($columnKeys as $colKey) {
				if (isset($cells[$rowKey][$colKey]) === true) {
					$shownCells[$rowKey][$colKey] = round($cells[$rowKey][$colKey], 2);
				}
			}
		}

		return [
			'rowAxis'      => $rowAxis,
			'columnAxis'   => $columnAxis,
			'rows'         => $this->describe(keys: $rowKeys, axis: $rowAxis, labels: $labels),
			'columns'      => $this->describe(keys: $columnKeys, axis: $columnAxis, labels: $labels),
			'cells'        => $shownCells,
			'rowTotals'    => array_map(static fn (float $sum): float => round($sum, 2), array_intersect_key($rowTotals, array_flip($rowKeys))),
			'columnTotals' => array_map(static fn (float $sum): float => round($sum, 2), array_intersect_key($columnTotals, array_flip($columnKeys))),
			'total'        => round(array_sum($rowTotals), 2),
			'capped'       => [
				'rows'    => count($rowTotals) > count($rowKeys),
				'columns' => count($columnTotals) > count($columnKeys),
			],
			'truncated'    => $this->truncated,
		];

	}//end shape()

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
	 * Key and label for each kept key; the label is null for "not set".
	 *
	 * @param array<int, string>                   $keys   The keys.
	 * @param string                               $axis   The axis.
	 * @param array<string, array<string, string>> $labels The lookups.
	 *
	 * @return array<int, array{key: string, label: ?string}> The descriptions.
	 */
	private function describe(array $keys, string $axis, array $labels): array {
		$described = [];
		foreach ($keys as $key) {
			$described[] = ['key' => $key, 'label' => $this->label(key: $key, axis: $axis, labels: $labels)];
		}

		return $described;

	}//end describe()

	/**
	 * The label of one key.
	 *
	 * @param string                               $key    The key.
	 * @param string                               $axis   The axis.
	 * @param array<string, array<string, string>> $labels The lookups.
	 *
	 * @return string|null The label, null when the key is empty.
	 */
	private function label(string $key, string $axis, array $labels): ?string {
		if ($key === '') {
			return null;
		}

		if ($axis === 'account' || ($axis === 'accountGroup' && str_starts_with($key, 'type:') === false)) {
			$name = ($labels['accounts'][$key] ?? '');
			if ($axis === 'accountGroup' && $name !== '') {
				return $name;
			}

			return trim($key . ' ' . $name);
		}

		if ($axis === 'accountGroup') {
			return substr($key, 5);
		}

		if ($axis === 'customer') {
			return ($labels['customers'][$key] ?? $key);
		}

		return $key;

	}//end label()

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
				->setRegister($this->settings->getRegisterSlug())
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
		} while (count($page) >= self::PAGE_SIZE);

		return $rows;

	}//end rows()
}//end class
