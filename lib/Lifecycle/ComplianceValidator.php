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

use OCA\Shillinq\AppInfo\Application;
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
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
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
			$rules = $this->objectService
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

			if (count($rules) === 0) {
				$this->logger->debug(
					'ComplianceValidator: no active BankingRules found for administration — transition permitted',
					['accountId' => $accountId, 'administrationId' => $administrationId]
				);
				return true;
			}

			$blockingFailures = [];
			foreach ($rules as $rule) {
				$passed = $this->evaluateRule(rule: $rule, account: $account, objectService: $this->objectService);
				if ($passed === false) {
					$severity = ($rule['severity'] ?? 'blocking');
					if ($severity === 'blocking') {
						$blockingFailures[] = ($rule['ruleNumber'] ?? 'unknown');
					}

					$this->logger->info(
						'ComplianceValidator: rule failed',
						[
							'accountId' => $accountId,
							'ruleNumber' => ($rule['ruleNumber'] ?? 'unknown'),
							'ruleType' => ($rule['ruleType'] ?? 'unknown'),
							'severity' => $severity,
						]
					);
				}
			}

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
		} catch (\Throwable $e) {
			$this->logger->error(
				'ComplianceValidator: rule evaluation failed — denying transition (fail-closed)',
				['accountId' => $accountId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isCompliant()

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
	 * @param object $objectService OR ObjectService, already resolved by the caller
	 *                              (reused here so a duplicate-IBAN lookup does not
	 *                              re-fetch the service from the container).
	 *
	 * @return bool True when the rule passes.
	 */
	private function evaluateRule(array $rule, array $account, object $objectService): bool {
		$ruleType = ($rule['ruleType'] ?? '');
		$criteria = ($rule['evaluationCriteria'] ?? []);

		return match ($ruleType) {
			'iban-format' => $this->evaluateIbanFormat(criteria: $criteria, account: $account),
			'approval-required' => $this->evaluateApprovalRequired(criteria: $criteria, account: $account),
			'segregation' => $this->evaluateSegregation(criteria: $criteria, account: $account, objectService: $objectService),
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
	 * @param object $objectService OR ObjectService (already resolved by the caller).
	 *
	 * @return bool True when no duplicate IBAN exists in the administration (or the
	 *              duplicate check is explicitly disabled via checkDuplicates=false);
	 *              false on a genuine duplicate OR when the check cannot be performed.
	 *
	 * @spec openspec/specs/bookkeeping-schatkistbankieren/spec.md
	 */
	private function evaluateSegregation(array $criteria, array $account, object $objectService): bool {
		$checkDuplicates = ($criteria['checkDuplicates'] ?? true);
		if ($checkDuplicates === false) {
			// Rule explicitly configured to skip the duplicate-IBAN check.
			return true;
		}

		$iban = ($account['iban'] ?? '');
		$administrationId = ($account['administrationId'] ?? '');
		$accountId = ($account['id'] ?? null);
		$accountNumber = ($account['accountNumber'] ?? null);
		$logAccountRef = ($accountId ?? $accountNumber ?? 'unknown');

		if ($iban === '' || $administrationId === '') {
			$this->logger->warning(
				'ComplianceValidator: segregation check indeterminate — missing iban or administrationId; denying (fail-closed, NOT a pass)',
				['accountId' => $logAccountRef, 'administrationId' => $administrationId]
			);
			return false;
		}

		try {
			$siblings = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('TreasuryAccount')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'iban' => $iban,
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ComplianceValidator: segregation check indeterminate — TreasuryAccount lookup failed; denying (fail-closed, NOT a pass)',
				['accountId' => $logAccountRef, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		if (is_array($siblings) === false) {
			$this->logger->warning(
				'ComplianceValidator: segregation check indeterminate — unexpected lookup result; denying (fail-closed, NOT a pass)',
				['accountId' => $logAccountRef, 'administrationId' => $administrationId]
			);
			return false;
		}

		$conflictingAccountIds = [];
		foreach ($siblings as $sibling) {
			if (is_array($sibling) === false) {
				continue;
			}

			$siblingId = ($sibling['id'] ?? null);
			$siblingNumber = ($sibling['accountNumber'] ?? null);

			$isSelf = ($accountId !== null && $siblingId === $accountId)
				|| ($accountId === null && $accountNumber !== null && $siblingNumber === $accountNumber);

			if ($isSelf === true) {
				continue;
			}

			$conflictingAccountIds[] = ($siblingId ?? $siblingNumber ?? 'unknown');
		}

		if (count($conflictingAccountIds) > 0) {
			$this->logger->warning(
				'ComplianceValidator: segregation rule violation — duplicate IBAN within administration',
				[
					'accountId' => $logAccountRef,
					'administrationId' => $administrationId,
					'iban' => $iban,
					'conflictingAccountIds' => $conflictingAccountIds,
				]
			);
			return false;
		}

		return true;
	}//end evaluateSegregation()

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
