<?php

/**
 * Cash flow statement by the indirect method, from posted ledger lines.
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
 * @spec openspec/changes/reporting-cash-flow-statement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use Throwable;

/**
 * The one source of cash flow figures for the page and the annual accounts.
 *
 * Every balance account carries a `cashFlowCategory` (design D1). Over a
 * period, each account's movement (credit positive, so a credit adds cash)
 * goes to the section of its category:
 *
 * - operating: the period result, plus depreciation, plus the movement of the
 *   working capital accounts;
 * - investing: the movement of the fixed asset accounts, without depreciation;
 * - financing: the movement of the financing and equity accounts;
 * - unclassified ("Niet ingedeeld"): the movement of every balance account
 *   without a category, each named, so the statement still reconciles.
 *
 * Depreciation is the credit movement, in the period, on the accumulated
 * depreciation accounts the fixed assets name, when those accounts are
 * classified as fixed assets. Year-closing entries are left out (they move no
 * cash). The sections add up to the change of the cash accounts; any rounding
 * difference left over is reported, never hidden (REQ-RCF-002).
 *
 * @spec openspec/changes/reporting-cash-flow-statement/tasks.md#task-2.1
 */
class CashFlowCalculator {

	/**
	 * The line carrying the period result.
	 */
	public const RESULT_LABEL = 'Resultaat';

	/**
	 * The line carrying the depreciation added back.
	 */
	public const DEPRECIATION_LABEL = 'Afschrijvingen';

	/**
	 * The section of balance accounts without a category.
	 */
	public const UNCLASSIFIED_LABEL = 'Niet ingedeeld';

	/**
	 * The heading of each section, as the page and the annual accounts show it.
	 */
	public const SECTION_LABELS = [
		'operating' => 'Kasstroom uit operationele activiteiten',
		'investing' => 'Kasstroom uit investeringsactiviteiten',
		'financing' => 'Kasstroom uit financieringsactiviteiten',
		'unclassified' => self::UNCLASSIFIED_LABEL,
	];

	/**
	 * The line carrying the change in cash the sections explain.
	 */
	public const CASH_CHANGE_LABEL = 'Mutatie liquide middelen';

	/**
	 * The categories a balance account can carry (design D1).
	 */
	public const CATEGORIES = ['cash', 'working-capital', 'fixed-assets', 'financing', 'equity'];

	/**
	 * Section per category; cash is the change the statement explains.
	 */
	private const SECTION_OF = [
		'working-capital' => 'operating',
		'fixed-assets' => 'investing',
		'financing' => 'financing',
		'equity' => 'financing',
	];

	/**
	 * Rows read per page.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The lines key scope of the result row: every profit and loss line.
	 *
	 * @var string
	 */
	public const SCOPE_RESULT = 'result';

	/**
	 * The lines key scope of the depreciation row: the credits on accumulated depreciation accounts.
	 *
	 * @var string
	 */
	public const SCOPE_DEPRECIATION = 'depreciation';

	/**
	 * A lines key: scope, first and last date, joined by a tilde.
	 *
	 * @var string
	 */
	private const LINES_KEY = '/^([A-Za-z0-9._-]{1,64})~(\d{4}-\d{2}-\d{2})~(\d{4}-\d{2}-\d{2})$/';

	/**
	 * Constructor.
	 *
	 * @param StatementCalculator    $statements       The posted-line figures.
	 * @param ObjectServiceInterface $objectService    OpenRegister object service, for the fixed assets.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 */
	public function __construct(
		private readonly StatementCalculator $statements,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {
	}//end __construct()

	/**
	 * The cash flow statement of a period, both dates inclusive.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $from             First posting date, YYYY-MM-DD.
	 * @param string $to               Last posting date, YYYY-MM-DD.
	 *
	 * @return array<string, mixed> administrationId, from, to, method, operating, investing, financing and
	 *                              unclassified (each rows and total), summary (one row per section and the
	 *                              change in cash), cash (opening, closing, change) and totals.
	 *
	 * @spec openspec/changes/reporting-cash-flow-statement/tasks.md#task-2.1
	 */
	public function between(string $administrationId, string $from, string $to): array {
		$period = $this->statements->cashFlowMovements(administrationId: $administrationId, from: $from, to: $to);
		$opening = $this->statements->cashFlowMovements(administrationId: $administrationId, from: '', to: self::dayBefore(date: $from));
		$accounts = $this->statements->accountsByNumber(administrationId: $administrationId);
		$depreciationAccounts = $this->depreciationAccounts(administrationId: $administrationId);

		$sections = ['operating' => [], 'investing' => [], 'financing' => [], 'unclassified' => []];
		$depreciation = 0.0;
		$cashChange = 0.0;
		foreach ($period['accounts'] as $number => $movement) {
			$account = (string)$number;
			$category = self::category(account: ($accounts[$account] ?? []));
			if ($category === 'cash') {
				// A debit on a cash account is cash coming in.
				$cashChange -= $movement['net'];
				continue;
			}

			$amount = $movement['net'];
			if ($category === 'fixed-assets' && isset($depreciationAccounts[$account]) === true) {
				$depreciation += $movement['credit'];
				$amount -= $movement['credit'];
			}

			if (abs($amount) < 0.005) {
				continue;
			}

			$section = (self::SECTION_OF[$category] ?? 'unclassified');
			$sections[$section][] = $this->row(account: $account, record: ($accounts[$account] ?? []), amount: $amount, from: $from, to: $to);
		}

		$operatingRows = [
			[
				'accountNumber' => null,
				'label' => self::RESULT_LABEL,
				'amount' => self::round(value: $period['result']),
				'from' => $from,
				'to' => $to,
				'linesKey' => self::linesKey(scope: self::SCOPE_RESULT, from: $from, to: $to),
			],
		];
		if (abs($depreciation) >= 0.005) {
			$operatingRows[] = [
				'accountNumber' => null,
				'label' => self::DEPRECIATION_LABEL,
				'amount' => self::round(value: $depreciation),
				'from' => $from,
				'to' => $to,
				'linesKey' => self::linesKey(scope: self::SCOPE_DEPRECIATION, from: $from, to: $to),
			];
		}

		$sections['operating'] = array_merge($operatingRows, $sections['operating']);

		$totals = [];
		foreach ($sections as $name => $rows) {
			$totals[$name] = self::round(value: array_sum(array_column($rows, 'amount')));
		}

		$openingCash = self::round(value: -$this->cashBalance(accounts: $accounts, movements: $opening['accounts']));
		$explained = ($totals['operating'] + $totals['investing'] + $totals['financing'] + $totals['unclassified']);

		$summary = [];
		foreach (self::SECTION_LABELS as $name => $label) {
			if ($name !== 'unclassified' || $sections['unclassified'] !== []) {
				$summary[] = ['label' => $label, 'amount' => $totals[$name]];
			}
		}

		$summary[] = ['label' => self::CASH_CHANGE_LABEL, 'amount' => self::round(value: $cashChange)];
		$difference = self::round(value: ($cashChange - $explained));
		if ($difference !== 0.0) {
			$summary[] = ['label' => 'Verschil', 'amount' => $difference];
		}

		return [
			'administrationId' => $administrationId,
			'from' => $from,
			'to' => $to,
			'method' => 'indirect',
			'operating' => ['rows' => $sections['operating'], 'total' => $totals['operating']],
			'investing' => ['rows' => $sections['investing'], 'total' => $totals['investing']],
			'financing' => ['rows' => $sections['financing'], 'total' => $totals['financing']],
			'unclassified' => ['rows' => $sections['unclassified'], 'total' => $totals['unclassified']],
			'summary' => $summary,
			'cash' => [
				'opening' => $openingCash,
				'change' => self::round(value: $cashChange),
				'closing' => self::round(value: ($openingCash + $cashChange)),
			],
			'totals' => [
				'operating' => $totals['operating'],
				'investing' => $totals['investing'],
				'financing' => $totals['financing'],
				'unclassified' => $totals['unclassified'],
				'netCashFlow' => self::round(value: $explained),
				'cashChange' => self::round(value: $cashChange),
				'difference' => $difference,
			],
		];
	}//end between()

	/**
	 * The account's cash flow category, or an empty string.
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return string One of CATEGORIES, or ''.
	 */
	private static function category(array $account): string {
		$category = strtolower(trim((string)($account['cashFlowCategory'] ?? '')));
		if (in_array($category, self::CATEGORIES, true) === true) {
			return $category;
		}

		return '';
	}//end category()

	/**
	 * The signed balance (credit positive) of the cash accounts in a set of movements.
	 *
	 * @param array<string, array<string, mixed>>                          $accounts  Account records by number.
	 * @param array<string, array{net: float, credit: float, debit: float}> $movements Movements by account number.
	 *
	 * @return float The credit-positive balance.
	 */
	private function cashBalance(array $accounts, array $movements): float {
		$balance = 0.0;
		foreach ($movements as $number => $movement) {
			if (self::category(account: ($accounts[(string)$number] ?? [])) === 'cash') {
				$balance += $movement['net'];
			}
		}

		return $balance;
	}//end cashBalance()

	/**
	 * The accumulated depreciation accounts the administration's fixed assets name.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, true> Account number => true.
	 */
	private function depreciationAccounts(string $administrationId): array {
		$numbers = [];
		foreach ($this->read(schema: 'FixedAsset', filters: ['administrationId' => $administrationId]) as $asset) {
			foreach (['accumulatedDepreciationAccountNumber', 'accumulatedDepAccountNumber'] as $field) {
				$number = trim((string)($asset[$field] ?? ''));
				if ($number !== '') {
					$numbers[$number] = true;
				}
			}
		}

		return $numbers;
	}//end depreciationAccounts()

	/**
	 * One statement row for an account.
	 *
	 * @param string               $account The account number.
	 * @param array<string, mixed> $record  The account record, or [].
	 * @param float                $amount  The cash effect.
	 * @param string               $from    Start of the range behind the figure.
	 * @param string               $to      End of the range behind the figure.
	 *
	 * @return array<string, mixed> accountNumber, accountId, label, amount, from, to.
	 */
	private function row(string $account, array $record, float $amount, string $from, string $to): array {
		return [
			'accountNumber' => $account,
			'accountId' => (string)($record['@self']['id'] ?? $record['id'] ?? ''),
			'label' => trim($account . ' ' . trim((string)($record['name'] ?? ''))),
			'amount' => self::round(value: $amount),
			'from' => $from,
			'to' => $to,
			'linesKey' => self::linesKey(scope: $account, from: $from, to: $to),
		];
	}//end row()

	/**
	 * Every row of a schema matching the filters, read in pages under the
	 * caller's own access rights; empty when the register cannot be read.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		$rows = [];
		$offset = 0;
		try {
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
		} catch (Throwable $e) {
			return [];
		}

		return $rows;
	}//end read()

	/**
	 * The posted ledger lines behind one cash flow figure, from its lines key.
	 *
	 * The lines add up to the figure: an account row lists that account's
	 * lines (without the depreciation credits on an accumulated depreciation
	 * account, which the depreciation row carries), the result row every
	 * profit and loss line, the depreciation row those credits.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $key              A row's `linesKey`: scope~from~to.
	 *
	 * @return array<string, mixed> scope, from, to, lines and total.
	 *
	 * @throws InvalidArgumentException When the key is malformed or its range is.
	 *
	 * @spec openspec/changes/reporting-cash-flow-statement/tasks.md#task-3.1
	 */
	public function lines(string $administrationId, string $key): array {
		if (preg_match(self::LINES_KEY, $key, $match) !== 1) {
			throw new InvalidArgumentException('key must be scope~from~to.');
		}

		[, $scope, $from, $to] = $match;
		StatementPeriods::assertRange(from: $from, to: $to);

		$depreciationAccounts = [];
		if ($scope !== self::SCOPE_RESULT) {
			$depreciationAccounts = $this->depreciationAccounts(administrationId: $administrationId);
		}

		if ($scope === self::SCOPE_RESULT) {
			$found = $this->statements->linesBetween(administrationId: $administrationId, accounts: [], class: 'pnl', from: $from, to: $to);
			$lines = $found['lines'];
		} else if ($scope === self::SCOPE_DEPRECIATION) {
			$lines = [];
			if ($depreciationAccounts !== []) {
				$found = $this->statements->linesBetween(
					administrationId: $administrationId,
					accounts: array_keys($depreciationAccounts),
					class: 'balance',
					from: $from,
					to: $to
				);
				$lines = array_values(array_filter($found['lines'], static fn (array $line): bool => $line['amount'] > 0.0));
			}
		} else {
			$found = $this->statements->linesBetween(administrationId: $administrationId, accounts: [$scope], class: 'balance', from: $from, to: $to);
			$lines = $found['lines'];
			if (isset($depreciationAccounts[$scope]) === true) {
				$lines = array_values(array_filter($lines, static fn (array $line): bool => $line['amount'] < 0.0));
			}
		}//end if

		return [
			'administrationId' => $administrationId,
			'scope' => $scope,
			'from' => $from,
			'to' => $to,
			'lines' => $lines,
			'total' => self::round(value: array_sum(array_column($lines, 'amount'))),
		];
	}//end lines()

	/**
	 * A row's lines key.
	 *
	 * @param string $scope An account number, or the result or depreciation scope.
	 * @param string $from  First date.
	 * @param string $to    Last date.
	 *
	 * @return string scope~from~to.
	 *
	 * @spec openspec/changes/reporting-cash-flow-statement/tasks.md#task-3.1
	 */
	public static function linesKey(string $scope, string $from, string $to): string {
		return $scope.'~'.$from.'~'.$to;
	}//end linesKey()

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
