<?php

/**
 * Shillinq EvaluateAllocationRulesAction
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
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Appends the allocation lines of active per-posting rules to a posting transaction.
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.3
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
	 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.3
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$transactionId = (string)($objectData['id'] ?? ($objectData['@self']['id'] ?? ''));
		if ($transactionId === '') {
			throw new RuntimeException('Cannot apply allocation rules to a transaction without an id.');
		}

		$rules = $this->activeRules(transaction: $objectData, parameters: $parameters);
		if ($rules === []) {
			return $objectData;
		}

		$lines = $this->lines(transaction: $objectData, transactionId: $transactionId);
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

		foreach ($rules as $rule) {
			$ruleId = (string)($rule['id'] ?? ($rule['@self']['id'] ?? ''));
			if ($ruleId === '' || isset($applied[$ruleId]) === true) {
				continue;
			}

			foreach ($sourceLines as $line) {
				if ($this->matches(pattern: (string)($rule['sourceAccountPattern'] ?? ''), account: (string)($line['accountNumber'] ?? '')) === false) {
					continue;
				}

				foreach ($this->allocationLines(rule: $rule, ruleId: $ruleId, line: $line, transaction: $objectData) as $row) {
					$row['transactionId'] = $transactionId;
					$row['lineNumber'] = $nextLineNumber++;
					$this->objectService->saveObject(object: $row, register: $this->register(), schema: 'GLLine');
				}
			}
		}//end foreach

		return $objectData;
	}//end execute()

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
		$dimensionField = (self::DIMENSION_FIELDS[(string)($rule['targetDimension'] ?? '')] ?? null);
		if ($dimensionField === null) {
			throw new RuntimeException(sprintf('Allocation rule "%s" has an unknown target dimension.', $name));
		}

		$sourceCents = (int)round((float)($line['amount'] ?? 0) * 100);
		$shares = $this->shares(rule: $rule, name: $name, sourceCents: $sourceCents);
		$allocated = array_sum(array_column($shares, 1));
		if ($allocated === 0) {
			return [];
		}

		$side = (string)($line['side'] ?? '');
		if (isset(self::OPPOSITE_SIDE[$side]) === false) {
			throw new RuntimeException(sprintf('Allocation rule "%s" matched a line with side "%s".', $name, $side));
		}

		$base = [
			'accountNumber' => (string)($line['accountNumber'] ?? ''),
			'currency' => (string)($line['currency'] ?? ($transaction['currency'] ?? 'EUR')),
			'periodId' => (string)($line['periodId'] ?? ($transaction['periodId'] ?? '')),
			'administrationId' => (string)($line['administrationId'] ?? ($transaction['administrationId'] ?? '')),
			'dimensions' => [
				'allocationRuleId' => $ruleId,
				'allocationSourceLineId' => (string)($line['id'] ?? ($line['@self']['id'] ?? '')),
			],
		];

		$rows = [
			array_merge(
				$base,
				[
					'side' => self::OPPOSITE_SIDE[$side],
					'amount' => round(($allocated / 100), 2),
					'description' => 'Allocation ' . $name,
				]
			),
		];
		foreach ($shares as [$code, $cents]) {
			if ($cents === 0) {
				continue;
			}

			$rows[] = array_merge(
				$base,
				[
					'side' => $side,
					'amount' => round(($cents / 100), 2),
					'description' => 'Allocation ' . $name . ' to ' . $code,
					$dimensionField => $code,
				]
			);
		}

		return $rows;
	}//end allocationLines()

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
		$targets = array_values(array_filter((array)($rule['targets'] ?? []), 'is_array'));
		if ($targets === []) {
			throw new RuntimeException(sprintf('Allocation rule "%s" has no targets.', $name));
		}

		$shares = [];
		if ($driver === 'fixed-percentage') {
			foreach ($targets as $target) {
				$shares[] = [(string)($target['code'] ?? ''), (int)round($sourceCents * (float)($target['percentage'] ?? 0) / 100)];
			}

			// A 100 percent split must allocate the whole amount: put the rounding remainder on the last target.
			$percentage = array_sum(array_map(static fn (array $t): float => (float)($t['percentage'] ?? 0), $targets));
			if (abs($percentage - 100.0) < 0.0001) {
				$last = (count($shares) - 1);
				$shares[$last][1] += ($sourceCents - array_sum(array_column($shares, 1)));
			}
		} else if ($driver === 'fixed-amount') {
			foreach ($targets as $target) {
				$shares[] = [(string)($target['code'] ?? ''), (int)round((float)($target['amount'] ?? 0) * 100)];
			}
		} else {
			throw new RuntimeException(
				sprintf(
					'Allocation rule "%s" uses driver "%s", which cannot run per posting. Set its cadence to monthly or pause it.',
					$name,
					$driver
				)
			);
		}//end if

		if (array_sum(array_column($shares, 1)) > $sourceCents) {
			throw new RuntimeException(sprintf('Allocation rule "%s" allocates more than the source line holds.', $name));
		}

		return $shares;
	}//end shares()

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

			if (str_ends_with($part, '*') === true) {
				if (str_starts_with($account, substr($part, 0, -1)) === true) {
					return true;
				}

				continue;
			}

			if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $range) === 1) {
				if (ctype_digit($account) === true && (int)$account >= (int)$range[1] && (int)$account <= (int)$range[2]) {
					return true;
				}

				continue;
			}

			if ($part === $account) {
				return true;
			}
		}//end foreach

		return false;
	}//end matches()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
