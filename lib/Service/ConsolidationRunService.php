<?php

/**
 * Consolidation Run Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The consolidation run of a ConsolidationPeriod (REQ-CONS-011): it sums the
 * per-account balances of the parent and every active member administration
 * of the group for the period, mapped onto the parent's chart through
 * ConsolidationMappingService, applies the elimination entries of the period,
 * and stores the outcome on the period's draft ConsolidatedBalance and
 * ConsolidatedIncomeStatement.
 *
 * Integral members count in full and proportional members by their ownership
 * percentage; equity-method members are not summed line by line. Amounts are
 * summed in integer cents.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Sums a group's administrations for one consolidation period and applies its eliminations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One run reads five schemas and writes two; the steps stay together so the totals have one source.
 */
class ConsolidationRunService {

	/**
	 * Account types on the balance sheet, with the sign that makes a normal balance positive.
	 *
	 * @var array<string,int>
	 */
	private const BALANCE_TYPES = ['assets' => 1, 'liabilities' => -1, 'equity' => -1];

	/**
	 * Account types on the income statement, with the sign that makes a normal balance positive.
	 *
	 * @var array<string,int>
	 */
	private const RESULT_TYPES = ['revenue' => -1, 'expenses' => 1];

	/**
	 * Construct the service.
	 *
	 * @param TrialBalanceServiceInterface $trialBalance  Per-account balances per administration and period.
	 * @param ConsolidationMappingService  $mapping       Maps a member's accounts onto the parent's chart.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     App config for the register slug.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly TrialBalanceServiceInterface $trialBalance,
		private readonly ConsolidationMappingService $mapping,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the consolidation of one period and store it on the period's reports.
	 *
	 * @param array<string,mixed> $period The ConsolidationPeriod record (with its id).
	 * @param DateTimeImmutable   $asOf   When the run happens; picks the account mappings in force.
	 *
	 * @return array<string,int|float> The totals (totalAssets, totalLiabilities, totalEquity, totalRevenue,
	 *                                  totalExpenses, netProfitTotal) and memberCount, eliminationCount, eliminationAmount.
	 *
	 * @throws InvalidArgumentException When the period names no group or no period end, the group is unknown, or a report is no longer a draft.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 */
	public function run(array $period, DateTimeImmutable $asOf): array {
		$periodRecordId = self::idOf(record: $period);
		$groupId = (string)($period['consolidationGroupId'] ?? '');
		$month = self::monthOf(date: (string)($period['periodEnd'] ?? ''));
		if ($periodRecordId === '' || $groupId === '' || $month === null) {
			throw new InvalidArgumentException('A consolidation period needs its id, a group and a period end before it can be run.');
		}

		$group = $this->record(schema: 'ConsolidationGroup', id: $groupId);
		$parentId = (string)($group['parentAdministrationId'] ?? '');
		if ($parentId === '') {
			throw new InvalidArgumentException('The consolidation group of this period is unknown or names no parent administration.');
		}

		$members = self::members(
			parentId: $parentId,
			defaultMethod: (string)($group['defaultConsolidationMethod'] ?? 'integral'),
			entities: $this->rows(schema: 'GroupEntity', filters: ['consolidationGroupId' => $groupId])
		);

		$types = [];
		$pre = $this->memberBalances(members: $members, parentId: $parentId, month: $month, asOf: $asOf, types: $types);
		$eliminations = $this->eliminations(periodRecordId: $periodRecordId, month: $month, members: $members);
		$adjustments = self::adjustments(eliminations: $eliminations);

		$post = $pre;
		foreach ($adjustments['cents'] as $account => $cents) {
			$post[$account] = (($post[$account] ?? 0) + $cents);
		}

		$totals = self::totals(balances: $post, types: $types);
		$this->store(
			periodRecordId: $periodRecordId,
			groupId: $groupId,
			reportDate: (string)$period['periodEnd'],
			lines: self::lines(pre: $pre, post: $post, types: $types, sources: $adjustments['sources']),
			totals: $totals
		);

		return $totals + [
			'memberCount' => count($members),
			'eliminationCount' => count($eliminations),
			'eliminationAmount' => self::euros(cents: $adjustments['debitCents']),
		];
	}//end run()

	/**
	 * The administrations to sum, each with the share that counts.
	 *
	 * The parent counts in full. A member counts when its membership is active:
	 * integral in full, proportional by its ownership percentage; equity-method
	 * members are left out.
	 *
	 * @param string                         $parentId      The parent administration.
	 * @param string                         $defaultMethod The group's default consolidation method.
	 * @param array<int,array<string,mixed>> $entities      The group's GroupEntity rows.
	 *
	 * @return array<string,float> Share per administration id.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 */
	public static function members(string $parentId, string $defaultMethod, array $entities): array {
		$members = [$parentId => 1.0];
		foreach ($entities as $entity) {
			$administrationId = (string)($entity['administrationId'] ?? '');
			if ($administrationId === '' || isset($members[$administrationId]) === true || ($entity['state'] ?? '') !== 'active') {
				continue;
			}

			$method = (string)($entity['consolidationMethod'] ?? '');
			if ($method === '') {
				$method = $defaultMethod;
			}

			if ($method === 'integral') {
				$members[$administrationId] = 1.0;
			} elseif ($method === 'proportional') {
				$members[$administrationId] = max(0.0, min(100.0, (float)($entity['ownershipPercentage'] ?? 0))) / 100;
			}
		}

		return $members;
	}//end members()

	/**
	 * The yyyy-mm month of a yyyy-mm-dd date.
	 *
	 * @param string $date The date.
	 *
	 * @return string|null The month, or null when the date is not yyyy-mm-dd.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 */
	public static function monthOf(string $date): ?string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		return $parts[1] . '-' . $parts[2];
	}//end monthOf()

	/**
	 * The net (debit minus credit) cents per account an elimination set adds, and which eliminations touch each account.
	 *
	 * Reads both line shapes: an EliminationEntry's {accountNumber, debit, credit}
	 * and an EliminationJournal's {glAccount, debitAmount, creditAmount}.
	 *
	 * @param array<int,array<string,mixed>> $eliminations The eliminations to apply.
	 *
	 * @return array{cents: array<string,int>, sources: array<string,string[]>, debitCents: int}
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 */
	public static function adjustments(array $eliminations): array {
		$cents = [];
		$sources = [];
		$debitTotal = 0;
		foreach ($eliminations as $elimination) {
			$source = self::idOf(record: $elimination);
			foreach ((array)($elimination['lines'] ?? []) as $line) {
				if (is_array($line) === false) {
					continue;
				}

				$account = (string)($line['accountNumber'] ?? ($line['glAccount'] ?? ''));
				if ($account === '') {
					continue;
				}

				$debit = (int)round(((float)($line['debit'] ?? ($line['debitAmount'] ?? 0))) * 100);
				$credit = (int)round(((float)($line['credit'] ?? ($line['creditAmount'] ?? 0))) * 100);
				$cents[$account] = (($cents[$account] ?? 0) + $debit - $credit);
				$debitTotal += $debit;
				if ($source !== '' && in_array($source, ($sources[$account] ?? []), true) === false) {
					$sources[$account][] = $source;
				}
			}
		}

		return ['cents' => $cents, 'sources' => $sources, 'debitCents' => $debitTotal];
	}//end adjustments()

	/**
	 * The report totals of a set of balances, each positive for a normal balance.
	 *
	 * @param array<string,int>    $balances Net cents per account.
	 * @param array<string,string> $types    Account type per account.
	 *
	 * @return array{totalAssets: float, totalLiabilities: float, totalEquity: float, totalRevenue: float, totalExpenses: float, netProfitTotal: float}
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 */
	public static function totals(array $balances, array $types): array {
		$sums = ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'revenue' => 0, 'expenses' => 0];
		$signs = (self::BALANCE_TYPES + self::RESULT_TYPES);
		foreach ($balances as $account => $cents) {
			$type = ($types[(string)$account] ?? '');
			if (isset($signs[$type]) === true) {
				$sums[$type] += ($signs[$type] * $cents);
			}
		}

		return [
			'totalAssets' => self::euros(cents: $sums['assets']),
			'totalLiabilities' => self::euros(cents: $sums['liabilities']),
			'totalEquity' => self::euros(cents: $sums['equity']),
			'totalRevenue' => self::euros(cents: $sums['revenue']),
			'totalExpenses' => self::euros(cents: $sums['expenses']),
			'netProfitTotal' => self::euros(cents: ($sums['revenue'] - $sums['expenses'])),
		];
	}//end totals()

	/**
	 * The report lines per account, split into the balance sheet and the income statement.
	 *
	 * @param array<string,int>      $pre     Net cents per account before the eliminations.
	 * @param array<string,int>      $post    Net cents per account after them.
	 * @param array<string,string>   $types   Account type per account.
	 * @param array<string,string[]> $sources Eliminations per account.
	 *
	 * @return array{balance: array<int,array<string,mixed>>, result: array<int,array<string,mixed>>}
	 */
	private static function lines(array $pre, array $post, array $types, array $sources): array {
		$accounts = array_map('strval', array_keys($post));
		sort($accounts);

		$lines = ['balance' => [], 'result' => []];
		foreach ($accounts as $account) {
			$type = ($types[$account] ?? '');
			$side = 'result';
			$sign = (self::RESULT_TYPES[$type] ?? null);
			if (isset(self::BALANCE_TYPES[$type]) === true) {
				$side = 'balance';
				$sign = self::BALANCE_TYPES[$type];
			}

			if ($sign === null) {
				continue;
			}

			$lines[$side][] = [
				'rapportageregelnummer' => $account,
				'rapportageregelnaam' => $type,
				'preEliminationTotal' => self::euros(cents: ($sign * ($pre[$account] ?? 0))),
				'eliminationAdjustments' => ($sources[$account] ?? []),
				'postEliminationTotal' => self::euros(cents: ($sign * $post[$account])),
			];
		}

		return $lines;
	}//end lines()

	/**
	 * Sum every member's closing balance per account, mapped onto the parent's chart.
	 *
	 * @param array<string,float>  $members  Share per administration.
	 * @param string               $parentId The parent administration.
	 * @param string               $month    The yyyy-mm period.
	 * @param DateTimeImmutable    $asOf     Picks the mapping in force.
	 * @param array<string,string> $types    Filled with the account type per (mapped) account.
	 *
	 * @return array<string,int> Net cents per account.
	 */
	private function memberBalances(array $members, string $parentId, string $month, DateTimeImmutable $asOf, array &$types): array {
		$sums = [];
		foreach ($members as $administrationId => $share) {
			$administrationId = (string)$administrationId;
			$mapping = null;
			if ($administrationId !== $parentId) {
				$mapping = $this->mapping->findActiveMapping(
					sourceAdministrationId: $administrationId,
					destinationAdministrationId: $parentId,
					asOf: $asOf
				);
			}

			$balance = $this->trialBalance->compute(administrationId: $administrationId, periodId: $month);
			foreach ((array)($balance['data'] ?? []) as $row) {
				$account = (string)($row['accountNumber'] ?? '');
				if ($account === '') {
					continue;
				}

				if ($mapping !== null) {
					$account = $this->mapping->applyAccountRule(mapping: $mapping, sourceAccount: $account);
				}

				$cents = (int)round(((float)($row['closingBalance'] ?? 0)) * 100 * $share);
				$sums[$account] = (($sums[$account] ?? 0) + $cents);
				$type = (string)($row['accountType'] ?? '');
				if ($type !== '' && (isset($types[$account]) === false || $administrationId === $parentId)) {
					$types[$account] = $type;
				}
			}
		}

		return $sums;
	}//end memberBalances()

	/**
	 * The eliminations of the period: its EliminationEntry rows that were not
	 * rejected, and the EliminationJournal rows the matching wrote for the
	 * month in one of the member administrations.
	 *
	 * @param string              $periodRecordId The ConsolidationPeriod id.
	 * @param string              $month          The yyyy-mm period.
	 * @param array<string,float> $members        Share per administration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function eliminations(string $periodRecordId, string $month, array $members): array {
		$applied = [];
		foreach ($this->rows(schema: 'EliminationEntry', filters: ['consolidationPeriodId' => $periodRecordId]) as $entry) {
			if (($entry['consolidationPeriodId'] ?? '') === $periodRecordId && ($entry['reviewStatus'] ?? '') !== 'rejected') {
				$applied[] = $entry;
			}
		}

		foreach ($this->rows(schema: 'EliminationJournal', filters: ['consolidationPeriodId' => $month]) as $journal) {
			if (($journal['consolidationPeriodId'] ?? '') === $month && isset($members[(string)($journal['administrationId'] ?? '')]) === true) {
				$applied[] = $journal;
			}
		}

		return $applied;
	}//end eliminations()

	/**
	 * Write the outcome on the period's balance and income statement, replacing a draft.
	 *
	 * @param string                                                                         $periodRecordId The ConsolidationPeriod id.
	 * @param string                                                                         $groupId        The group.
	 * @param string                                                                         $reportDate     The period end.
	 * @param array{balance: array<int,array<string,mixed>>, result: array<int,array<string,mixed>>} $lines          The report lines.
	 * @param array<string,float>                                                            $totals         The totals.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a report of the period is no longer a draft.
	 */
	private function store(string $periodRecordId, string $groupId, string $reportDate, array $lines, array $totals): void {
		$base = ['consolidationGroupId' => $groupId, 'consolidationPeriodId' => $periodRecordId, 'reportDate' => $reportDate, 'state' => 'draft'];
		$reports = [
			'ConsolidatedBalance' => $base + [
				'lines' => $lines['balance'],
				'totalAssets' => $totals['totalAssets'],
				'totalLiabilities' => $totals['totalLiabilities'],
				'totalEquity' => $totals['totalEquity'],
			],
			'ConsolidatedIncomeStatement' => $base + [
				'lines' => $lines['result'],
				'totalRevenue' => $totals['totalRevenue'],
				'totalExpenses' => $totals['totalExpenses'],
				'netProfitTotal' => $totals['netProfitTotal'],
			],
		];

		$existing = [];
		foreach (array_keys($reports) as $schema) {
			$existing[$schema] = $this->draftOf(schema: $schema, periodRecordId: $periodRecordId);
		}

		foreach ($reports as $schema => $report) {
			$uuid = $existing[$schema];
			if ($uuid === '') {
				$uuid = null;
			}

			$this->objectService->saveObject(object: $report, register: $this->register(), schema: $schema, uuid: $uuid);
		}

		$this->logger->info('ConsolidationRunService: consolidation stored', ['consolidationPeriodId' => $periodRecordId] + $totals);
	}//end store()

	/**
	 * The id of the period's existing draft report of a schema, or '' when it has none.
	 *
	 * @param string $schema         ConsolidatedBalance or ConsolidatedIncomeStatement.
	 * @param string $periodRecordId The ConsolidationPeriod id.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the period's report is no longer a draft.
	 */
	private function draftOf(string $schema, string $periodRecordId): string {
		foreach ($this->rows(schema: $schema, filters: ['consolidationPeriodId' => $periodRecordId]) as $report) {
			if (($report['consolidationPeriodId'] ?? '') !== $periodRecordId) {
				continue;
			}

			if (($report['state'] ?? 'draft') !== 'draft') {
				throw new InvalidArgumentException('The ' . $schema . ' of this period is ' . (string)$report['state'] . '; a final report is not run again.');
			}

			return self::idOf(record: $report);
		}

		return '';
	}//end draftOf()

	/**
	 * One record by id, as an array; empty when it is absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function record(string $schema, string $id): array {
		$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);

		return (ObjectIdentifier::findOne(scoped: $scoped, id: $id) ?? []);
	}//end record()

	/**
	 * The rows of a schema matching the filters, as arrays.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$rows = [];
		foreach ($found as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			} elseif (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = (array)$row->getObject();
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Cents as euros.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return float
	 */
	private static function euros(int $cents): float {
		return ($cents / 100.0);
	}//end euros()

	/**
	 * The id of a record, from its payload or its @self block.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return string
	 */
	private static function idOf(array $record): string {
		return (string)($record['id'] ?? ($record['@self']['id'] ?? ($record['uuid'] ?? '')));
	}//end idOf()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
