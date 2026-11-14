<?php

/**
 * Compliance Validator
 *
 * Multi-criteria compliance precondition for TreasuryAccount lifecycle transitions.
 * This is an ADR-031 exception: a single-method PHP lifecycle guard called *by* the
 * OR lifecycle engine from the TreasuryAccount schema's x-openregister-lifecycle
 * `requires:` clause. It does not replace the declarative lifecycle — it is a thin
 * seam for multi-criteria conditional rule evaluation that the OR engine cannot yet
 * express declaratively.
 *
 * Remove this class when OR's lifecycle engine supports multi-criteria conditional
 * precondition clauses (ADR-031 §Exceptions, point 1).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-schatkistbankieren/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Single-method compliance precondition for TreasuryAccount lifecycle transitions.
 *
 * Evaluates all active BankingRule criteria applicable to the treasury account's
 * administration. If ALL criteria pass, returns true (transition permitted).
 * If ANY criterion fails with severity=blocking, returns false.
 *
 * Called from x-openregister-lifecycle `requires: OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant`
 * on the `activate`, `monitor`, and `reactivate` transitions.
 *
 * ADR-031 exception: multi-criteria conditional logic not yet expressible declaratively.
 *
 * @spec openspec/specs/bookkeeping-schatkistbankieren/spec.md
 */
class ComplianceValidator {
	/**
	 * Construct ComplianceValidator with lazy-loaded ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for dynamic register slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for compliance audit logging.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Evaluate all active BankingRule criteria for the account's administration.
	 *
	 * Per REQ-SCHATKIST-005: ALL active BankingRule records applicable to the
	 * administrationId are evaluated. If any blocking rule fails, returns false.
	 * Non-blocking failures (warning/informational) are logged but do not block.
	 *
	 * Fail-closed: any unexpected error denies the transition and logs the reason.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object array (loaded by OR lifecycle engine).
	 *
	 * @return bool True when all active blocking rules pass; false otherwise.
	 *
	 * @spec openspec/specs/bookkeeping-schatkistbankieren/spec.md
	 */
	public function isCompliant(array $account): bool {
		$administrationId = ($account['administrationId'] ?? null);
		$accountId = ($account['id'] ?? $account['accountNumber'] ?? 'unknown');

		if ($administrationId === null || $administrationId === '') {
			$this->logger->error(
				'ComplianceValidator: missing administrationId — denying transition (fail-closed)',
				['accountId' => $accountId]
			);
			return false;
		}

		try {
			return $this->rulesPass(account: $account, rules: $this->activeRules(administrationId: $administrationId));
		} catch (\Throwable $e) {
			$this->logger->error(
				'ComplianceValidator: rule evaluation failed — denying transition (fail-closed)',
				['accountId' => $accountId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isCompliant()

	/**
	 * The active BankingRule records of the administration.
	 *
	 * @param mixed $administrationId The administration id.
	 *
	 * @return array<int, mixed> The rules.
	 */
	private function activeRules(mixed $administrationId): array {
		return $this->objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('BankingRule')
			->findAll(
				[
					'filters' => [
						'isActive' => true,
						'administrationId' => $administrationId,
					],
				]
			);
	}//end activeRules()

	/**
	 * Whether every blocking rule passes; no rules at all permits the
	 * transition.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object array.
	 * @param array<int, mixed>    $rules   The active BankingRules.
	 *
	 * @return bool True when no blocking rule fails.
	 */
	private function rulesPass(array $account, array $rules): bool {
		$accountId = $this->accountRef(account: $account);
		if (count($rules) === 0) {
			$this->logger->debug(
				'ComplianceValidator: no active BankingRules found for administration — transition permitted',
				['accountId' => $accountId, 'administrationId' => $account['administrationId']]
			);
			return true;
		}

		$blockingFailures = $this->blockingFailures(account: $account, rules: $rules);
		if (count($blockingFailures) > 0) {
			$this->logger->warning(
				'ComplianceValidator: blocking rules failed — denying transition',
				['accountId' => $accountId, 'failedRules' => $blockingFailures]
			);
			return false;
		}

		$this->logger->debug(
			'ComplianceValidator: all active blocking rules passed',
			['accountId' => $accountId, 'rulesEvaluated' => count($rules)]
		);
		return true;
	}//end rulesPass()

	/**
	 * Evaluate every rule, logging each failure; answer the rule numbers of
	 * the failed blocking rules.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object array.
	 * @param array<int, mixed>    $rules   The active BankingRules.
	 *
	 * @return list<mixed> The failed blocking rule numbers.
	 */
	private function blockingFailures(array $account, array $rules): array {
		$blockingFailures = [];
		foreach ($rules as $rule) {
			if ($this->evaluateRule(rule: $rule, account: $account) === true) {
				continue;
			}

			$severity = ($rule['severity'] ?? 'blocking');
			if ($severity === 'blocking') {
				$blockingFailures[] = ($rule['ruleNumber'] ?? 'unknown');
			}

			$this->logger->info(
				'ComplianceValidator: rule failed',
				[
					'accountId' => $this->accountRef(account: $account),
					'ruleNumber' => ($rule['ruleNumber'] ?? 'unknown'),
					'ruleType' => ($rule['ruleType'] ?? 'unknown'),
					'severity' => $severity,
				]
			);
		}

		return $blockingFailures;
	}//end blockingFailures()

	/**
	 * The account as named in the audit log: id, else accountNumber, else 'unknown'.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object array.
	 *
	 * @return mixed The reference.
	 */
	private function accountRef(array $account): mixed {
		return ($account['id'] ?? $account['accountNumber'] ?? 'unknown');
	}//end accountRef()

	/**
	 * Evaluate a single BankingRule against the treasury account.
	 *
	 * Per REQ-SCHATKIST-003 ruleType semantics:
	 * - iban-format: validates account.iban against evaluationCriteria.pattern
	 * - segregation: no other TreasuryAccount in the same administration may share
	 *   this account's IBAN (evaluationCriteria.checkDuplicates) — see evaluateSegregation().
	 * - approval-required: checks that approvalStatus is 'approved' or 'not-required'
	 * - Other types (transaction-limit, reporting-period): returns true (not yet
	 *   implemented; non-blocking default — no seed rule uses these ruleTypes today,
	 *   tracked separately, see shillinq issue filed alongside this change)
	 *
	 * @param array<string, mixed> $rule BankingRule object.
	 * @param array<string, mixed> $account TreasuryAccount object.
	 *
	 * @return bool True when the rule passes.
	 */
	private function evaluateRule(array $rule, array $account): bool {
		$ruleType = ($rule['ruleType'] ?? '');
		$criteria = ($rule['evaluationCriteria'] ?? []);

		return match ($ruleType) {
			'iban-format' => $this->evaluateIbanFormat(criteria: $criteria, account: $account),
			'approval-required' => $this->evaluateApprovalRequired(criteria: $criteria, account: $account),
			'segregation' => $this->evaluateSegregation(criteria: $criteria, account: $account),
			default => true,
		};

	}//end evaluateRule()

	/**
	 * Validate IBAN against pattern in evaluationCriteria.
	 *
	 * @param array<string, mixed> $criteria Rule's evaluationCriteria.
	 * @param array<string, mixed> $account TreasuryAccount object.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) $pattern is externally
	 *     supplied (rule config) and can be malformed; `@` suppresses the
	 *     resulting PHP warning and the `=== 1` check treats any other
	 *     result (including a malformed-pattern `false`) as no-match
	 *     (fail-safe).
	 */
	private function evaluateIbanFormat(array $criteria, array $account): bool {
		$pattern = ($criteria['pattern'] ?? null);
		$iban = ($account['iban'] ?? '');

		if ($pattern === null || $iban === '') {
			return false;
		}

		// Use ~ as delimiter so a / in pattern does not break the regex.
		return (@preg_match(pattern: '~' . $pattern . '~', subject: $iban) === 1);
	}//end evaluateIbanFormat()

	/**
	 * Evaluate a `segregation` BankingRule: per REQ-SCHATKIST-003's scenario
	 * "Segregation rule prevents duplicate IBANs within administration" and the
	 * `activate` transition's declared precondition ("no other TreasuryAccount in
	 * administrationId has the same iban when evaluationCriteria.checkDuplicates=true"),
	 * no other `TreasuryAccount` in the same administration may share this account's IBAN.
	 *
	 * Honest tri-state result: a genuine duplicate IBAN returns false (violation —
	 * logged with the account, IBAN, administration, and the conflicting account
	 * id(s) so an auditor can follow it). Missing data (no IBAN/administrationId)
	 * or a lookup failure ALSO returns false — fail-closed — but is logged
	 * distinctly as *indeterminate* rather than a violation, so the audit trail
	 * never conflates "we could not check" with either a genuine pass or a
	 * genuine failure. This method never fabricates a pass.
	 *
	 * @param array<string, mixed> $criteria Rule's evaluationCriteria (checkDuplicates).
	 * @param array<string, mixed> $account TreasuryAccount object under evaluation.
	 *
	 * @return bool True when no duplicate IBAN exists in the administration (or the
	 *              duplicate check is explicitly disabled via checkDuplicates=false);
	 *              false on a genuine duplicate OR when the check cannot be performed.
	 *
	 * @spec openspec/specs/bookkeeping-schatkistbankieren/spec.md
	 */
	private function evaluateSegregation(array $criteria, array $account): bool {
		$checkDuplicates = ($criteria['checkDuplicates'] ?? true);
		if ($checkDuplicates === false) {
			// Rule explicitly configured to skip the duplicate-IBAN check.
			return true;
		}

		$siblings = $this->ibanSiblings(account: $account);
		if ($siblings === null) {
			return false;
		}

		$conflictingAccountIds = $this->conflictingAccountIds(account: $account, siblings: $siblings);
		if (count($conflictingAccountIds) > 0) {
			$this->logger->warning(
				'ComplianceValidator: segregation rule violation — duplicate IBAN within administration',
				[
					'accountId' => $this->accountRef(account: $account),
					'administrationId' => ($account['administrationId'] ?? ''),
					'iban' => ($account['iban'] ?? ''),
					'conflictingAccountIds' => $conflictingAccountIds,
				]
			);
			return false;
		}

		return true;
	}//end evaluateSegregation()

	/**
	 * The TreasuryAccounts of the administration sharing this account's IBAN,
	 * or null — logged as indeterminate, never as a pass — when the IBAN or
	 * administration is missing or the lookup fails.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object under evaluation.
	 *
	 * @return array<int, mixed>|null The siblings, or null when indeterminate.
	 */
	private function ibanSiblings(array $account): array|null {
		$iban = ($account['iban'] ?? '');
		$administrationId = ($account['administrationId'] ?? '');
		$context = ['accountId' => $this->accountRef(account: $account), 'administrationId' => $administrationId];
		if ($iban === '' || $administrationId === '') {
			$this->logger->warning(
				'ComplianceValidator: segregation check indeterminate — missing iban or administrationId; denying (fail-closed, NOT a pass)',
				$context
			);
			return null;
		}

		return $this->lookupSiblings(account: $account, context: $context);
	}//end ibanSiblings()

	/**
	 * Look the IBAN up among the administration's TreasuryAccounts.
	 *
	 * @param array<string, mixed> $account TreasuryAccount object under evaluation.
	 * @param array<string, mixed> $context The log context (account, administration).
	 *
	 * @return array<int, mixed>|null The siblings, or null when the lookup failed.
	 */
	private function lookupSiblings(array $account, array $context): array|null {
		try {
			$siblings = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('TreasuryAccount')
				->findAll(['filters' => ['administrationId' => $account['administrationId'], 'iban' => $account['iban']]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ComplianceValidator: segregation check indeterminate — TreasuryAccount lookup failed; denying (fail-closed, NOT a pass)',
				$context + ['exception' => $e->getMessage()]
			);
			return null;
		}

		return $siblings;
	}//end lookupSiblings()

	/**
	 * The references of the siblings that are other accounts (not this one).
	 *
	 * @param array<string, mixed> $account  TreasuryAccount object under evaluation.
	 * @param array<int, mixed>    $siblings TreasuryAccounts sharing the IBAN.
	 *
	 * @return list<mixed> The conflicting account references.
	 */
	private function conflictingAccountIds(array $account, array $siblings): array {
		$conflicting = [];
		foreach ($siblings as $sibling) {
			if (is_array($sibling) === false || $this->isSameAccount(account: $account, sibling: $sibling) === true) {
				continue;
			}

			$conflicting[] = ($sibling['id'] ?? $sibling['accountNumber'] ?? 'unknown');
		}

		return $conflicting;
	}//end conflictingAccountIds()

	/**
	 * Whether a sibling is the account itself: by id when the account has
	 * one, else by accountNumber.
	 *
	 * @param array<string, mixed> $account The account under evaluation.
	 * @param array<string, mixed> $sibling A TreasuryAccount sharing the IBAN.
	 *
	 * @return bool True when the sibling is this account.
	 */
	private function isSameAccount(array $account, array $sibling): bool {
		$accountId = ($account['id'] ?? null);
		if ($accountId !== null) {
			return ($sibling['id'] ?? null) === $accountId;
		}

		$accountNumber = ($account['accountNumber'] ?? null);

		return $accountNumber !== null && ($sibling['accountNumber'] ?? null) === $accountNumber;
	}//end isSameAccount()

	/**
	 * Check that the account's approvalStatus satisfies the approval-required rule.
	 *
	 * Per REQ-SCHATKIST-003 / ADR-022: approval is consumed from OR's approval-workflow.
	 * This check verifies the approvalStatus field that OR sets after workflow completion.
	 *
	 * @param array<string, mixed> $criteria Rule's evaluationCriteria.
	 * @param array<string, mixed> $account TreasuryAccount object.
	 *
	 * @return bool
	 */
	private function evaluateApprovalRequired(array $criteria, array $account): bool {
		$requiresApproval = ($criteria['requiresTreasurerApproval'] ?? true);
		if ($requiresApproval === false) {
			return true;
		}

		$approvalStatus = ($account['approvalStatus'] ?? 'pending');
		return in_array(needle: $approvalStatus, haystack: ['approved', 'not-required'], strict: true);
	}//end evaluateApprovalRequired()
}//end class
