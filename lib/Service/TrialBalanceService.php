<?php

/**
 * Trial Balance Service
 *
 * Tier-2 read-only trial-balance computation (REQ-TB-008, REQ-TB-018). Computes
 * the per-account opening / movement / closing breakdown for a fiscal period from
 * existing GLTransaction + GLLine + Account data using the real OpenRegister
 * ObjectService API (find / findAll) — there is NO TrialBalanceLine record
 * authored by operators; the rows are materialised on demand (design.md D3).
 *
 * Per ADR-031 the equivalent declarative aggregation shape is documented on the
 * TrialBalanceLine schema (x-openregister-aggregations.trialBalanceByAccountPeriod);
 * this service is the engine-side fallback for the prior-period opening-balance
 * carry and the GLLine→Account join, which the declarative aggregation engine
 * cannot yet express (REQ-TB-018).
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-1
 *
 * KNOWINGLY DANGLING — do not repoint this tag until shillinq#500 is answered.
 * `#task-3-1` cannot resolve (`- [x] Task 3.1:` makes the checker read `Task`
 * as the item id), but no canonical target is honest: REQ-TB-001 forbids this
 * very class by name ("MUST NOT introduce a `TrialBalanceService.php`"), and
 * the archived change's REQ-TB-008, which mandates it, was never canonical —
 * that change was archived with `--skip-specs` as having no spec delta. The
 * prohibition predates this file by nine days. A tag that resolves to a
 * requirement the code violates reports conformance; a dangling one reports
 * work to do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes a period-scoped, per-account trial balance from the general ledger.
 *
 * Reads are scoped to a single administration + period (REQ-TB-004, REQ-TB-016,
 * REQ-TB-017): callers pass the administrationId resolved from the authenticated
 * user's context, never a client-supplied trust boundary. Movements come from
 * GLLine rows whose parent GLTransaction belongs to the administration and period;
 * the opening balance is carried from the prior period's net GL position
 * (REQ-TB-002); the closing balance is opening + (debit - credit) (REQ-TB-003).
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-1
 *
 * KNOWINGLY DANGLING — do not repoint this tag until shillinq#500 is answered.
 * `#task-3-1` cannot resolve (`- [x] Task 3.1:` makes the checker read `Task`
 * as the item id), but no canonical target is honest: REQ-TB-001 forbids this
 * very class by name ("MUST NOT introduce a `TrialBalanceService.php`"), and
 * the archived change's REQ-TB-008, which mandates it, was never canonical —
 * that change was archived with `--skip-specs` as having no spec delta. The
 * prohibition predates this file by nine days. A tag that resolves to a
 * requirement the code violates reports conformance; a dangling one reports
 * work to do.
 */
class TrialBalanceService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param TrialBalanceCalculator $calculator Pure-logic arithmetic helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TrialBalanceCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the trial balance for one administration + period (REQ-TB-008).
	 *
	 * Returns a sorted list of per-account rows plus aggregate totals. Each row
	 * carries periodId, accountNumber, accountName, accountType, openingBalance,
	 * debitMovement, creditMovement, closingBalance, currency, parentAccountNumber.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-TB-016).
	 * @param string $periodId Fiscal period to report (REQ-TB-004).
	 * @param array<string,mixed> $filters Optional filters; supports 'priorPeriodId'
	 *                                     to source opening balances (REQ-TB-002).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int, totals: array<string,float>, isBalanced: bool}
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-1
	 *
	 * KNOWINGLY DANGLING — do not repoint this tag until shillinq#500 is answered.
	 * `#task-3-1` cannot resolve (`- [x] Task 3.1:` makes the checker read `Task`
	 * as the item id), but no canonical target is honest: REQ-TB-001 forbids this
	 * very class by name ("MUST NOT introduce a `TrialBalanceService.php`"), and
	 * the archived change's REQ-TB-008, which mandates it, was never canonical —
	 * that change was archived with `--skip-specs` as having no spec delta. The
	 * prohibition predates this file by nine days. A tag that resolves to a
	 * requirement the code violates reports conformance; a dangling one reports
	 * work to do.
	 */
	public function compute(string $administrationId, string $periodId, array $filters = []): array {
		$accounts = $this->fetchAccounts(administrationId: $administrationId);

		// Period movements per account (cents), and the prior-period closing
		// balances used as opening balances (REQ-TB-002).
		$movements = $this->movementsByAccount(administrationId: $administrationId, periodId: $periodId);
		$priorPeriod = (string)($filters['priorPeriodId'] ?? '');
		$openingCents = [];
		if ($priorPeriod !== '') {
			$priorMovements = $this->movementsByAccount(administrationId: $administrationId, periodId: $priorPeriod);
			foreach ($priorMovements as $account => $mv) {
				// Prior closing = prior opening (assumed 0 at first period) + net.
				$openingCents[$account] = ($mv['debit'] - $mv['credit']);
			}
		}

		$rows = [];
		// Union of accounts that exist and accounts that have movements.
		$accountNumbers = array_unique(array_merge(array_keys($accounts), array_keys($movements)));
		sort($accountNumbers);
		foreach ($accountNumbers as $accountNumber) {
			$account = ($accounts[$accountNumber] ?? []);
			$mv = ($movements[$accountNumber] ?? ['debit' => 0, 'credit' => 0]);
			$opening = (int)($openingCents[$accountNumber] ?? 0);
			$closing = $this->calculator->closingCents(
				openingCents: $opening,
				debitCents: $mv['debit'],
				creditCents: $mv['credit']
			);
			$rows[] = [
				'periodId' => $periodId,
				'accountNumber' => (string)$accountNumber,
				'accountName' => ($account['name'] ?? null),
				'accountType' => (string)($account['accountType'] ?? ''),
				'openingBalance' => $this->calculator->fromCents(cents: $opening),
				'debitMovement' => $this->calculator->fromCents(cents: $mv['debit']),
				'creditMovement' => $this->calculator->fromCents(cents: $mv['credit']),
				'closingBalance' => $this->calculator->fromCents(cents: $closing),
				'currency' => (string)($account['currency'] ?? 'EUR'),
				'parentAccountNumber' => ($account['parentAccountNumber'] ?? null),
				'administrationId' => $administrationId,
			];
		}//end foreach

		$totals = $this->calculator->totals(rows: $rows);

		// Expose short `debit`/`credit` aliases alongside the canonical
		// `totalDebit`/`totalCredit` keys (REQ-TB-002) so consumers that read the
		// movement footing under either name resolve the same value.
		$totals['debit'] = ($totals['totalDebit'] ?? 0);
		$totals['credit'] = ($totals['totalCredit'] ?? 0);

		return [
			'data' => $rows,
			'total' => count($rows),
			'totals' => $totals,
			'isBalanced' => $this->calculator->isBalanced(rows: $rows),
		];

	}//end compute()

	/**
	 * Sum debit and credit movements per account for an administration + period.
	 *
	 * Resolves the administration's GLTransactions for the period, then sums the
	 * amounts of their non-eliminated GLLine children grouped by accountNumber and
	 * side. All arithmetic is in integer cents.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Fiscal period.
	 *
	 * @return array<string,array{debit:int,credit:int}> accountNumber => debit/credit cents.
	 */
	private function movementsByAccount(string $administrationId, string $periodId): array {
		$register = $this->register();

		// Transactions that belong to this administration + period (REQ-TB-017 scoping).
		$transactions = $this->objectService
			->setRegister($register)
			->setSchema('GLTransaction')
			->findAll(
				['filters' => ['administrationId' => $administrationId, 'periodId' => $periodId]]
			);

		$transactionIds = [];
		foreach ($transactions as $rawTransaction) {
			// OpenRegister's findAll() returns ObjectEntity instances; normalise.
			$transaction = $this->asArray(row: $rawTransaction);
			$id = ($transaction['id'] ?? ($transaction['@self']['id'] ?? null));
			if ($id !== null) {
				$transactionIds[(string)$id] = true;
			}
		}

		// GLLines for the period; cross-check the parent transaction is in scope.
		$lines = $this->objectService
			->setRegister($register)
			->setSchema('GLLine')
			->findAll(['filters' => ['periodId' => $periodId]]);

		$byAccount = [];
		foreach ($lines as $rawLine) {
			// OpenRegister's findAll() returns ObjectEntity instances; normalise.
			$line = $this->asArray(row: $rawLine);
			if ($this->lineInScope(line: $line, transactionIds: $transactionIds) === false) {
				continue;
			}

			$account = (string)($line['accountNumber'] ?? '');
			if (isset($byAccount[$account]) === false) {
				$byAccount[$account] = ['debit' => 0, 'credit' => 0];
			}

			$cents = $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			$side = 'credit';
			if (($line['side'] ?? '') === 'debit') {
				$side = 'debit';
			}

			$byAccount[$account][$side] += $cents;
		}//end foreach

		return $byAccount;
	}//end movementsByAccount()

	/**
	 * Decide whether a GLLine counts toward the period trial balance.
	 *
	 * Excludes eliminated lines, lines whose parent transaction is out of the
	 * administration/period scope (REQ-TB-017), and lines without an account.
	 *
	 * @param array<string,mixed> $line The GLLine record.
	 * @param array<string,bool> $transactionIds In-scope transaction ids (set membership).
	 *
	 * @return bool True when the line should be summed.
	 */
	private function lineInScope(array $line, array $transactionIds): bool {
		if (($line['eliminationFlag'] ?? false) === true) {
			return false;
		}

		$transactionId = (string)($line['transactionId'] ?? '');
		if ($transactionIds !== [] && isset($transactionIds[$transactionId]) === false) {
			return false;
		}

		return ((string)($line['accountNumber'] ?? '') !== '');
	}//end lineInScope()

	/**
	 * Fetch the administration's chart-of-accounts keyed by accountNumber (REQ-TB-006, REQ-TB-020).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> accountNumber => Account object.
	 */
	private function fetchAccounts(string $administrationId): array {
		$accounts = $this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($accounts as $rawAccount) {
			// OpenRegister's findAll() returns ObjectEntity instances; normalise.
			$account = $this->asArray(row: $rawAccount);
			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $account;
			}
		}

		return $byNumber;
	}//end fetchAccounts()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
	 *
	 * @return array<string,mixed> The object as an array (empty array when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
