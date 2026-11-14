<?php

/**
 * Shillinq EvaluateAllocationRulesAction
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Handler for the allocation action declared on `GLTransaction.post`
 * (ledger-posting-path REQ-LPP-001, REQ-LPP-002). The declaration named
 * `evaluate-allocation-rules`, which OpenRegister's LifecycleActionRegistry
 * could never resolve to a shillinq handler (see MaterialiseGlTransactionAction
 * for why), so posting a GL transaction aborted (#516). It now names this
 * class by FQCN.
 *
 * On post it reads every `AllocationRule` in state `active` with cadence
 * `per-posting` for the transaction's administration. For each rule whose
 * `sourceAccountPattern` matches a line, it appends a balanced set of GLLine
 * rows: one line that takes the allocated amount off the source line's
 * account, and one line per target that puts its share back on the same
 * account under the target's dimension (cost centre, cost carrier or
 * project). The transaction still balances in total. A rule already applied
 * to the transaction is not applied again (idempotency on `transactionId`).
 *
 * The drivers `fixed-percentage` and `fixed-amount` carry their split on the
 * rule itself. `volume` and `headcount` need a driver source this app does
 * not compute per posting, so an active per-posting rule with one of those
 * drivers refuses the post by name instead of being skipped in silence.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Appends the allocation lines of active per-posting rules to a posting transaction.
 *
 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
 */
class EvaluateAllocationRulesAction implements LifecycleActionInterface {

	/**
	 * GLLine field per `AllocationRule.targetDimension`.
	 *
	 * @var array<string, string>
	 */
	private const DIMENSION_FIELDS = [
		'cost-center' => 'costCenterCode',
		'cost-carrier' => 'costCarrierCode',
		'project' => 'projectCode',
	];

	/**
	 * The other side of a posting line.
	 *
	 * @var array<string, string>
	 */
	private const OPPOSITE_SIDE = [
		'debit' => 'credit',
		'credit' => 'debit',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig Register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Apply the matching allocation rules to the posting transaction.
	 *
	 * @param array<string,mixed> $objectData The GLTransaction after it moved to posted.
	 * @param array<string,mixed> $previousData The GLTransaction before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (`filter`).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The transaction, unchanged: this action only writes GLLine rows.
	 *
	 * @throws RuntimeException When the transaction has no id or an active rule cannot be applied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$transactionId = $this->transactionIdOf(transaction: $objectData);
		if ($transactionId === '') {
			throw new RuntimeException('Cannot apply allocation rules to a transaction without an id.');
		}

		$rules = $this->activeRules(transaction: $objectData, parameters: $parameters);
		if ($rules === []) {
			return $objectData;
		}

		$lines = $this->lines(transaction: $objectData, transactionId: $transactionId);
		[$applied, $sourceLines, $nextLineNumber] = $this->partitionLines(lines: $lines);

		foreach ($rules as $rule) {
			if ($this->isPending(rule: $rule, applied: $applied) === false) {
				continue;
			}

			$nextLineNumber = $this->applyRule(rule: $rule, sourceLines: $sourceLines, transaction: $objectData, nextLineNumber: $nextLineNumber);
		}

		return $objectData;
	}//end execute()

	/**
	 * The id of a transaction, or '' when it has none.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction.
	 *
	 * @return string
	 */
	private function transactionIdOf(array $transaction): string {
		return (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
	}//end transactionIdOf()

	/**
	 * The id of a rule, or '' when it has none.
	 *
	 * @param array<string,mixed> $rule The AllocationRule.
	 *
	 * @return string
	 */
	private function ruleIdOf(array $rule): string {
		return (string)($rule['id'] ?? ($rule['@self']['id'] ?? ''));
	}//end ruleIdOf()

	/**
	 * Split the transaction's lines into the rules already applied and the source lines.
	 *
	 * @param array<int,array<string,mixed>> $lines The transaction's GLLines.
	 *
	 * @return array{0:array<string,true>,1:list<array<string,mixed>>,2:int} Applied rule ids, source lines, next line number.
	 */
	private function partitionLines(array $lines): array {
		$applied = [];
		$sourceLines = [];
		$nextLineNumber = 1;
		foreach ($lines as $line) {
			$nextLineNumber = max($nextLineNumber, ((int)($line['lineNumber'] ?? 0) + 1));
			$ruleId = (string)($line['dimensions']['allocationRuleId'] ?? '');
			if ($ruleId !== '') {
				$applied[$ruleId] = true;
				continue;
			}

			$sourceLines[] = $line;
		}

		return [$applied, $sourceLines, $nextLineNumber];
	}//end partitionLines()

	/**
	 * Whether a rule has an id and has not yet been applied to the transaction.
	 *
	 * @param array<string,mixed> $rule    The AllocationRule.
	 * @param array<string,true>  $applied Rule ids already applied.
	 *
	 * @return bool
	 */
	private function isPending(array $rule, array $applied): bool {
		$ruleId = $this->ruleIdOf(rule: $rule);
		return ($ruleId !== '' && isset($applied[$ruleId]) === false);
	}//end isPending()

	/**
	 * Save the allocation rows one rule adds for every source line it matches.
	 *
	 * @param array<string,mixed>       $rule           The AllocationRule.
	 * @param list<array<string,mixed>> $sourceLines    The source GLLines.
	 * @param array<string,mixed>       $transaction    The GLTransaction.
	 * @param int                       $nextLineNumber The next free line number.
	 *
	 * @return int The next free line number afterwards.
	 *
	 * @throws RuntimeException When the rule cannot be applied.
	 */
	private function applyRule(array $rule, array $sourceLines, array $transaction, int $nextLineNumber): int {
		$ruleId = $this->ruleIdOf(rule: $rule);
		$transactionId = $this->transactionIdOf(transaction: $transaction);
		foreach ($sourceLines as $line) {
			if ($this->matches(pattern: (string)($rule['sourceAccountPattern'] ?? ''), account: (string)($line['accountNumber'] ?? '')) === false) {
				continue;
			}

			foreach ($this->allocationLines(rule: $rule, ruleId: $ruleId, line: $line, transaction: $transaction) as $row) {
				$row['transactionId'] = $transactionId;
				$row['lineNumber'] = $nextLineNumber++;
				$this->objectService->saveObject(object: $row, register: $this->register(), schema: 'GLLine');
			}
		}

		return $nextLineNumber;
	}//end applyRule()

	/**
	 * The balanced rows one rule adds for one source line.
	 *
	 * @param array<string,mixed> $rule The AllocationRule.
	 * @param string $ruleId The rule id.
	 * @param array<string,mixed> $line The source GLLine.
	 * @param array<string,mixed> $transaction The GLTransaction.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws RuntimeException When the rule's driver or dimension cannot be applied per posting.
	 */
	private function allocationLines(array $rule, string $ruleId, array $line, array $transaction): array {
		$name = (string)($rule['name'] ?? $ruleId);
		$dimensionField = $this->dimensionField(rule: $rule, name: $name);

		$sourceCents = (int)round((float)($line['amount'] ?? 0) * 100);
		$shares = $this->shares(rule: $rule, name: $name, sourceCents: $sourceCents);
		$allocated = array_sum(array_column($shares, 1));
		if ($allocated === 0) {
			return [];
		}

		$side = $this->sideOf(line: $line, name: $name);

		$base = $this->baseRow(ruleId: $ruleId, line: $line, transaction: $transaction);

		$rows = [$this->counterRow(base: $base, side: $side, allocated: $allocated, name: $name)];

		$sideBase = array_merge($base, ['side' => $side]);

		return array_merge($rows, $this->shareRows(sideBase: $sideBase, shares: $shares, name: $name, dimensionField: $dimensionField));
	}//end allocationLines()

	/**
	 * The GLLine field a rule's target dimension writes to.
	 *
	 * @param array<string,mixed> $rule The AllocationRule.
	 * @param string              $name The rule name, for messages.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the target dimension is unknown.
	 */
	private function dimensionField(array $rule, string $name): string {
		$dimensionField = (self::DIMENSION_FIELDS[(string)($rule['targetDimension'] ?? '')] ?? null);
		if ($dimensionField === null) {
			throw new RuntimeException(sprintf('Allocation rule "%s" has an unknown target dimension.', $name));
		}

		return $dimensionField;
	}//end dimensionField()

	/**
	 * The side of a matched source line.
	 *
	 * @param array<string,mixed> $line The source GLLine.
	 * @param string              $name The rule name, for messages.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the side has no opposite.
	 */
	private function sideOf(array $line, string $name): string {
		$side = (string)($line['side'] ?? '');
		if (isset(self::OPPOSITE_SIDE[$side]) === false) {
			throw new RuntimeException(sprintf('Allocation rule "%s" matched a line with side "%s".', $name, $side));
		}

		return $side;
	}//end sideOf()

	/**
	 * The fields every allocation row of a source line shares.
	 *
	 * @param string              $ruleId      The rule id.
	 * @param array<string,mixed> $line        The source GLLine.
	 * @param array<string,mixed> $transaction The GLTransaction.
	 *
	 * @return array<string,mixed>
	 */
	private function baseRow(string $ruleId, array $line, array $transaction): array {
		return [
			'accountNumber' => (string)($line['accountNumber'] ?? ''),
			'currency' => (string)($line['currency'] ?? ($transaction['currency'] ?? 'EUR')),
			'periodId' => (string)($line['periodId'] ?? ($transaction['periodId'] ?? '')),
			'administrationId' => (string)($line['administrationId'] ?? ($transaction['administrationId'] ?? '')),
			'dimensions' => [
				'allocationRuleId' => $ruleId,
				'allocationSourceLineId' => (string)($line['id'] ?? ($line['@self']['id'] ?? '')),
			],
		];
	}//end baseRow()

	/**
	 * The row that takes the allocated amount off the source side.
	 *
	 * @param array<string,mixed> $base      The shared fields.
	 * @param string              $side      The source line's side.
	 * @param int                 $allocated The allocated cents.
	 * @param string              $name      The rule name.
	 *
	 * @return array<string,mixed>
	 */
	private function counterRow(array $base, string $side, int $allocated, string $name): array {
		return array_merge(
			$base,
			[
				'side' => self::OPPOSITE_SIDE[$side],
				'amount' => round(($allocated / 100), 2),
				'description' => 'Allocation ' . $name,
			]
		);
	}//end counterRow()

	/**
	 * One row per non-zero target share, on the source side.
	 *
	 * @param array<string,mixed>         $sideBase       The shared fields with the source side.
	 * @param list<array{0:string,1:int}> $shares         Pairs of [target code, cents].
	 * @param string                      $name           The rule name.
	 * @param string                      $dimensionField The GLLine field the target code goes in.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function shareRows(array $sideBase, array $shares, string $name, string $dimensionField): array {
		$rows = [];
		foreach ($shares as [$code, $cents]) {
			if ($cents === 0) {
				continue;
			}

			$rows[] = array_merge(
				$sideBase,
				[
					'amount' => round(($cents / 100), 2),
					'description' => 'Allocation ' . $name . ' to ' . $code,
					$dimensionField => $code,
				]
			);
		}

		return $rows;
	}//end shareRows()

	/**
	 * Each target's share of the source amount, in cents.
	 *
	 * @param array<string,mixed> $rule The AllocationRule.
	 * @param string $name The rule name, for messages.
	 * @param int $sourceCents The source line amount in cents.
	 *
	 * @return list<array{0: string, 1: int}> Pairs of [target code, cents].
	 *
	 * @throws RuntimeException When the driver cannot be applied per posting.
	 */
	private function shares(array $rule, string $name, int $sourceCents): array {
		$driver = (string)($rule['driver'] ?? '');
		$targets = $this->perPostingTargets(rule: $rule, name: $name, driver: $driver);

		$shares = $this->rawShares(targets: $targets, driver: $driver, sourceCents: $sourceCents);

		// A 100 percent split must allocate the whole amount: put the rounding remainder on the last target.
		$shares = $this->topUpLastShare(shares: $shares, targets: $targets, driver: $driver, sourceCents: $sourceCents);

		if (array_sum(array_column($shares, 1)) > $sourceCents) {
			throw new RuntimeException(sprintf('Allocation rule "%s" allocates more than the source line holds.', $name));
		}

		return $shares;
	}//end shares()

	/**
	 * A rule's targets, when its driver can run per posting.
	 *
	 * @param array<string,mixed> $rule   The AllocationRule.
	 * @param string              $name   The rule name, for messages.
	 * @param string              $driver The rule's driver.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws RuntimeException When the rule has no targets or its driver cannot run per posting.
	 */
	private function perPostingTargets(array $rule, string $name, string $driver): array {
		$targets = array_values(array_filter((array)($rule['targets'] ?? []), 'is_array'));
		if ($targets === []) {
			throw new RuntimeException(sprintf('Allocation rule "%s" has no targets.', $name));
		}

		if (in_array($driver, ['fixed-percentage', 'fixed-amount'], true) === false) {
			throw new RuntimeException(
				sprintf(
					'Allocation rule "%s" uses driver "%s", which cannot run per posting. Set its cadence to monthly or pause it.',
					$name,
					$driver
				)
			);
		}

		return $targets;
	}//end perPostingTargets()

	/**
	 * Each target's share before the rounding remainder is placed.
	 *
	 * @param list<array<string,mixed>> $targets     The rule's targets.
	 * @param string                    $driver      The rule's driver.
	 * @param int                       $sourceCents The source line amount in cents.
	 *
	 * @return list<array{0: string, 1: int}>
	 */
	private function rawShares(array $targets, string $driver, int $sourceCents): array {
		$shares = [];
		foreach ($targets as $target) {
			$cents = (int)round((float)($target['amount'] ?? 0) * 100);
			if ($driver === 'fixed-percentage') {
				$cents = (int)round($sourceCents * (float)($target['percentage'] ?? 0) / 100);
			}

			$shares[] = [(string)($target['code'] ?? ''), $cents];
		}

		return $shares;
	}//end rawShares()

	/**
	 * Put the rounding remainder of a 100 percent split on the last target.
	 *
	 * @param list<array{0: string, 1: int}> $shares      The raw shares.
	 * @param list<array<string,mixed>>      $targets     The rule's targets.
	 * @param string                         $driver      The rule's driver.
	 * @param int                            $sourceCents The source line amount in cents.
	 *
	 * @return list<array{0: string, 1: int}>
	 */
	private function topUpLastShare(array $shares, array $targets, string $driver, int $sourceCents): array {
		$percentage = array_sum(array_map(static fn (array $t): float => (float)($t['percentage'] ?? 0), $targets));
		if ($driver === 'fixed-percentage' && abs($percentage - 100.0) < 0.0001) {
			$last = (count($shares) - 1);
			$shares[$last][1] += ($sourceCents - array_sum(array_column($shares, 1)));
		}

		return $shares;
	}//end topUpLastShare()

	/**
	 * Whether an account number matches a rule's `sourceAccountPattern`.
	 *
	 * Accepts a comma-separated list of an exact number (`4000`), a range
	 * (`4800-4899`) or a prefix with a trailing `*` (`48*`).
	 *
	 * @param string $pattern The rule's pattern.
	 * @param string $account The line's account number.
	 *
	 * @return bool
	 */
	private function matches(string $pattern, string $account): bool {
		if ($account === '') {
			return false;
		}

		foreach (explode(',', $pattern) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}

			if ($this->partMatches(part: $part, account: $account) === true) {
				return true;
			}
		}

		return false;
	}//end matches()

	/**
	 * Whether an account number matches one part of a pattern: a prefix, a range or an exact number.
	 *
	 * @param string $part    One trimmed, non-empty part of the pattern.
	 * @param string $account The line's account number.
	 *
	 * @return bool
	 */
	private function partMatches(string $part, string $account): bool {
		if (str_ends_with($part, '*') === true) {
			return str_starts_with($account, substr($part, 0, -1));
		}

		if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $range) === 1) {
			return $this->inRange(account: $account, range: $range);
		}

		return $part === $account;
	}//end partMatches()

	/**
	 * Whether a numeric account number lies within a range.
	 *
	 * @param string            $account The line's account number.
	 * @param array<int,string> $range   The range match: [1] low, [2] high.
	 *
	 * @return bool
	 */
	private function inRange(string $account, array $range): bool {
		return (ctype_digit($account) === true && (int)$account >= (int)$range[1] && (int)$account <= (int)$range[2]);
	}//end inRange()

	/**
	 * The active per-posting rules for the transaction's administration.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction.
	 * @param array<string,mixed> $parameters The declared actionParameters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function activeRules(array $transaction, array $parameters): array {
		$filters = (array)($parameters['filter'] ?? []);
		$filters['lifecycleState'] = 'active';
		$filters['cadence'] = 'per-posting';
		$administrationId = (string)($transaction['administrationId'] ?? '');
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$rules = [];
		foreach ($this->find(schema: 'AllocationRule', filters: $filters) as $rule) {
			// Re-check the filter here: a filter the store ignores must not start moving money.
			if (($rule['lifecycleState'] ?? '') === 'active' && ($rule['cadence'] ?? '') === 'per-posting'
				&& ($administrationId === '' || (string)($rule['administrationId'] ?? '') === $administrationId)
			) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}//end activeRules()

	/**
	 * The transaction's GLLine rows, keyed by its id and by its number.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction.
	 * @param string $transactionId The GLTransaction id.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function lines(array $transaction, string $transactionId): array {
		$keys = array_unique(array_filter([$transactionId, (string)($transaction['transactionNumber'] ?? '')]));
		$lines = [];
		foreach ($keys as $key) {
			foreach ($this->find(schema: 'GLLine', filters: ['transactionId' => $key]) as $line) {
				$lines[(string)($line['id'] ?? ($line['@self']['id'] ?? count($lines)))] = $line;
			}
		}

		return array_values($lines);
	}//end lines()

	/**
	 * Find objects of one schema as plain arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function find(string $schema, array $filters): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$out = [];
		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$out[] = $data;
			}
		}

		return $out;
	}//end find()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
