<?php

/**
 * Expense Reimbursement Guard
 *
 * ADR-031 exception-path lifecycle guard for ExpenseClaimEntry dual-mode
 * settlement transitions: settlement-mode consistency on submit,
 * markup-approval gating on approve, pass-through path on markInvoiced,
 * and GL-reversal precondition on the high-privilege changeSettlementMode
 * transition. Referenced from
 * lib/Settings/register.d/expense-reimbursement-or-passthrough.json
 * ExpenseClaimEntry lifecycle transitions.
 *
 * ADR-031 exception reason: cross-schema cardinality checks (every linked
 * Receipt / MileageEntry / PerDiem has matching settlementMode), policy
 * threshold lookups (ReimbursementPolicy.requiresMarkupApprovalThreshold),
 * GL-reversal state checks (T1 REQ-GL-004), and pass-through customer AR
 * account validation are not yet expressible in the declarative lifecycle
 * DSL. Replace with declarative conditions when the engine supports
 * cross-schema membership filters + threshold expressions + reversal
 * state lookups.
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
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-11
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-16
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-17
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-22
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for ExpenseClaimEntry dual-mode settlement
 * transitions (REQ-ERP-001 through REQ-ERP-011).
 *
 * Referenced from lib/Settings/register.d/expense-reimbursement-or-passthrough.json
 * ExpenseClaimEntry lifecycle:
 * - transitions.submit.requires                → requireSettlementModeConsistency
 * - transitions.approve.requires               → requireMarkupApprovalIfThreshold
 * - transitions.markInvoiced.requires          → requirePassThroughMode
 * - transitions.changeSettlementMode.requires  → requireGlReversalForModeChange
 *
 * All methods are fail-closed: they return false (deny transition) on any
 * exception per ADR-031 / CWE-863. The lifecycle engine then surfaces the
 * denial through the standard transition-rejected pathway.
 *
 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
 *
 * @SuppressWarnings(PHPMD.LongVariable) Pre-existing debt (issue #506):
 *     not in the project's calibrated length threshold; deferred pending
 *     a dedicated rename pass.
 */
class ExpenseReimbursementGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the configured register slug; falls back to 'shillinq'.
	 *
	 * @return string The non-empty register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Precondition for the submit transition (REQ-ERP-001, REQ-ERP-002, REQ-ERP-003).
	 *
	 * Validates:
	 * 1. The claim has a non-null settlementMode (reimbursable | pass-through).
	 * 2. Every linked Receipt / MileageEntry / PerDiem has matching or null
	 *    settlementMode — mixed-mode claims are rejected per REQ-ERP-003.
	 * 3. Pass-through claims have a non-null linkedCustomerId on every
	 *    pass-through line item per REQ-ERP-002 (validation rule).
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the claim may be submitted.
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-11
	 */
	public function requireSettlementModeConsistency(array $claim): bool {
		try {
			$claimMode = ($claim['settlementMode'] ?? null);
			if ($claimMode === null || $claimMode === '') {
				$this->logger->info(
					'ExpenseReimbursementGuard: claim has no settlementMode — denying submit (REQ-ERP-003)',
					['claimId' => ($claim['id'] ?? 'unknown')]
				);
				return false;
			}

			if (in_array($claimMode, ['reimbursable', 'pass-through'], true) === false) {
				$this->logger->info(
					'ExpenseReimbursementGuard: claim has invalid settlementMode — denying submit',
					[
						'claimId' => ($claim['id'] ?? 'unknown'),
						'settlementMode' => $claimMode,
					]
				);
				return false;
			}

			return $this->allItemsMatchSettlementMode(
				claimId: (string)($claim['id'] ?? ''),
				claimMode: $claimMode,
				receiptIds: (array)($claim['receiptIds'] ?? []),
				mileageIds: (array)($claim['mileageIds'] ?? []),
				perDiemIds: (array)($claim['perDiemIds'] ?? []),
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requireSettlementModeConsistency failed — denying submit (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireSettlementModeConsistency()

	/**
	 * Precondition for the approve transition (REQ-ERP-006).
	 *
	 * When the claim's ReimbursementPolicy declares a
	 * requiresMarkupApprovalThreshold AND the claim's pass-through markup
	 * amount (totalPassThroughAmount minus the pre-markup cost portion)
	 * meets-or-exceeds that threshold, this guard denies the standard
	 * approve transition — the claim must be routed through the OR
	 * approval-workflow extra-approver gate per ADR-022.
	 *
	 * Below-threshold or no-threshold claims pass through unaffected so OR's
	 * standard approve flow can complete.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the claim may be approved via the standard gate.
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-17
	 */
	public function requireMarkupApprovalIfThreshold(array $claim): bool {
		try {
			// No pass-through markup ⇒ no extra gate needed.
			$settlementMode = ($claim['settlementMode'] ?? null);
			if ($settlementMode !== 'pass-through') {
				return true;
			}

			$policyId = ($claim['reimbursementPolicyId'] ?? null);
			if ($policyId === null || $policyId === '') {
				// No explicit policy ⇒ admin defaults apply, no extra gate.
				return true;
			}

			$threshold = $this->getMarkupApprovalThresholdForPolicy(policyId: (string)$policyId);
			if ($threshold === null) {
				return true;
			}

			$totalPassThroughAmount = (float)($claim['totalPassThroughAmount'] ?? 0.0);
			$markupAmount = $this->computePassThroughMarkupAmount(claim: $claim, totalPassThrough: $totalPassThroughAmount);

			if ($markupAmount >= $threshold) {
				$this->logger->info(
					'ExpenseReimbursementGuard: pass-through markup meets/exceeds policy threshold — '
					. 'denying standard approve, deferring to OR approval-workflow extra-approver gate (REQ-ERP-006)',
					[
						'claimId' => ($claim['id'] ?? 'unknown'),
						'policyId' => $policyId,
						'markupAmount' => $markupAmount,
						'threshold' => $threshold,
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requireMarkupApprovalIfThreshold failed — denying approve (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireMarkupApprovalIfThreshold()

	/**
	 * Precondition for the markInvoiced transition (REQ-ERP-007 pass-through closure).
	 *
	 * Only pass-through claims may transition posted → invoiced; reimbursable
	 * claims use the reimbursed closure instead.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the pass-through claim may transition to invoiced.
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-11
	 */
	public function requirePassThroughMode(array $claim): bool {
		try {
			$settlementMode = ($claim['settlementMode'] ?? null);
			if ($settlementMode !== 'pass-through') {
				$this->logger->info(
					'ExpenseReimbursementGuard: markInvoiced denied — claim is not pass-through (REQ-ERP-007)',
					[
						'claimId' => ($claim['id'] ?? 'unknown'),
						'settlementMode' => $settlementMode,
					]
				);
				return false;
			}

			// Pass-through path requires the GL transaction back-reference to exist.
			$glPassThroughTransactionId = ($claim['glPassThroughTransactionId'] ?? null);
			if ($glPassThroughTransactionId === null || $glPassThroughTransactionId === '') {
				$this->logger->info(
					'ExpenseReimbursementGuard: markInvoiced denied — glPassThroughTransactionId missing',
					['claimId' => ($claim['id'] ?? 'unknown')]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requirePassThroughMode failed — denying (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requirePassThroughMode()

	/**
	 * Precondition for the changeSettlementMode transition (REQ-ERP-011).
	 *
	 * High-privilege transition: the existing GL transaction
	 * (glReimbursableTransactionId or glPassThroughTransactionId per the
	 * current settlementMode) MUST already be marked reversed per T1
	 * REQ-GL-004 before the operator may change the mode and re-post.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the GL transaction is reversed and the mode-change is permitted.
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-16
	 */
	public function requireGlReversalForModeChange(array $claim): bool {
		try {
			$settlementMode = ($claim['settlementMode'] ?? null);
			$glField = 'glReimbursableTransactionId';
			if ($settlementMode === 'pass-through') {
				$glField = 'glPassThroughTransactionId';
			}

			$glTxnId = ($claim[$glField] ?? null);

			if ($glTxnId === null || $glTxnId === '') {
				// No GL entry to reverse ⇒ permit (claim was never posted under this mode).
				return true;
			}

			return $this->isGlTransactionReversed(glTxnId: (string)$glTxnId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requireGlReversalForModeChange failed — denying (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireGlReversalForModeChange()

	/**
	 * Verify every linked line item has settlementMode matching the claim
	 * (or null — items inherit the claim mode by default per REQ-ERP-003).
	 *
	 * Items with a non-null settlementMode that diverges from the claim
	 * settlementMode are rejected (mixed-mode claim).
	 *
	 * @param string $claimId Claim ID for log context.
	 * @param string $claimMode The claim-level settlementMode.
	 * @param array<string> $receiptIds Receipt record IDs.
	 * @param array<string> $mileageIds MileageEntry record IDs.
	 * @param array<string> $perDiemIds PerDiem record IDs.
	 *
	 * @return bool True when all items match (or inherit) the claim mode.
	 */
	private function allItemsMatchSettlementMode(
		string $claimId,
		string $claimMode,
		array $receiptIds,
		array $mileageIds,
		array $perDiemIds,
	): bool {
		$register = $this->getRegisterSlug();

		$checks = [
			['schema' => 'Receipt', 'ids' => $receiptIds],
			['schema' => 'MileageEntry', 'ids' => $mileageIds],
			['schema' => 'PerDiem', 'ids' => $perDiemIds],
		];

		foreach ($checks as $check) {
			foreach ($check['ids'] as $itemId) {
				$stringId = (string)$itemId;
				if ($stringId === '') {
					continue;
				}

				$item = $this->objectService
					->setRegister($register)
					->setSchema($check['schema'])
					->find($stringId);

				if ($item === null) {
					continue;
				}

				// ADR-084: find() returns an ObjectEntityInterface, so the is_array()
				// arm was unreachable and `(array)$item` cast the ENTITY — yielding
				// its (mangled, private) property names rather than the stored
				// payload, so `settlementMode` was never present and the mixed-mode
				// rejection below could not fire.
				$itemArray = (array)$item->jsonSerialize();

				$itemMode = ($itemArray['settlementMode'] ?? null);

				if ($itemMode !== null && $itemMode !== '' && $itemMode !== $claimMode) {
					$this->logger->info(
						'ExpenseReimbursementGuard: mixed-mode claim rejected (REQ-ERP-003)',
						[
							'claimId' => $claimId,
							'itemId' => $stringId,
							'schema' => $check['schema'],
							'claimMode' => $claimMode,
							'itemMode' => $itemMode,
						]
					);
					return false;
				}

				// Pass-through items require linkedCustomerId (REQ-ERP-002 validation).
				if ($claimMode === 'pass-through') {
					$linkedCustomerId = ($itemArray['linkedCustomerId'] ?? null);
					if ($linkedCustomerId === null || $linkedCustomerId === '') {
						$this->logger->info(
							'ExpenseReimbursementGuard: pass-through item missing linkedCustomerId (REQ-ERP-002)',
							[
								'claimId' => $claimId,
								'itemId' => $stringId,
								'schema' => $check['schema'],
							]
						);
						return false;
					}
				}
			}//end foreach
		}//end foreach

		return true;
	}//end allItemsMatchSettlementMode()

	/**
	 * Look up the requiresMarkupApprovalThreshold field on the named
	 * ReimbursementPolicy record. Returns null when no threshold is set or
	 * the policy cannot be resolved (no extra gate applies in either case).
	 *
	 * @param string $policyId The policyId to resolve.
	 *
	 * @return float|null The threshold amount in base currency, or null.
	 */
	private function getMarkupApprovalThresholdForPolicy(string $policyId): ?float {
		$register = $this->getRegisterSlug();

		$matches = $this->objectService
			->setRegister($register)
			->setSchema('ReimbursementPolicy')
			->findAll(
				[
					'filters' => ['policyId' => $policyId],
					'limit' => 1,
				]
			);

		if (empty($matches) === true) {
			return null;
		}

		$policy = (array)$matches[0];
		if (is_array($matches[0]) === true) {
			$policy = $matches[0];
		}

		$threshold = ($policy['requiresMarkupApprovalThreshold'] ?? null);
		if ($threshold === null) {
			return null;
		}

		return (float)$threshold;
	}//end getMarkupApprovalThresholdForPolicy()

	/**
	 * Compute the markup portion of a pass-through claim total per REQ-ERP-006.
	 *
	 * Formula:
	 *
	 *   markupAmount = totalPassThroughAmount - (totalPassThroughAmount / (1 + avgMarkupRate))
	 *
	 * where avgMarkupRate is the cost-weighted average of markupRateApplied
	 * across all pass-through line items. For mixed percentage / fixedAmount
	 * rules the engine falls back to the spec's simpler formulation:
	 *
	 *   markupAmount = totalPassThroughAmount × avgMarkupRate / (1 + avgMarkupRate)
	 *
	 * When the rate cannot be derived (e.g. no rule resolved), the method
	 * returns the totalPassThroughAmount itself so threshold checks remain
	 * conservative (fail toward extra approval).
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 * @param float $totalPassThrough Pre-resolved totalPassThroughAmount.
	 *
	 * @return float Markup amount in base currency.
	 */
	private function computePassThroughMarkupAmount(array $claim, float $totalPassThrough): float {
		if ($totalPassThrough <= 0.0) {
			return 0.0;
		}

		$weighted = $this->weightedAverageMarkupRate(claim: $claim);
		if ($weighted === null || $weighted <= 0.0) {
			// Conservative fallback: treat the whole pass-through amount as markup
			// so threshold checks gate toward extra approval.
			return $totalPassThrough;
		}

		return ($totalPassThrough * $weighted) / (1.0 + $weighted);
	}//end computePassThroughMarkupAmount()

	/**
	 * Cost-weighted average markupRateApplied across all pass-through line items.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return float|null The weighted average, or null when no rates can be resolved.
	 */
	private function weightedAverageMarkupRate(array $claim): ?float {
		$register = $this->getRegisterSlug();

		$totalCost = 0.0;
		$totalWeighted = 0.0;

		$sources = [
			['schema' => 'Receipt', 'ids' => (array)($claim['receiptIds'] ?? []), 'amountField' => 'amountInBaseCurrency'],
			['schema' => 'MileageEntry', 'ids' => (array)($claim['mileageIds'] ?? []), 'amountField' => 'totalAmount'],
			['schema' => 'PerDiem', 'ids' => (array)($claim['perDiemIds'] ?? []), 'amountField' => 'allowanceAmount'],
		];

		foreach ($sources as $source) {
			foreach ($source['ids'] as $itemId) {
				$stringId = (string)$itemId;
				if ($stringId === '') {
					continue;
				}

				$item = $this->objectService
					->setRegister($register)
					->setSchema($source['schema'])
					->find($stringId);

				if ($item === null) {
					continue;
				}

				// ADR-084: see the note in the mixed-mode check above — `(array)$item`
				// cast the ENTITY, not its payload, so no pass-through item was ever
				// recognised and the cost total below always came out zero.
				$itemArray = (array)$item->jsonSerialize();

				if (($itemArray['settlementMode'] ?? null) !== 'pass-through') {
					continue;
				}

				$cost = (float)($itemArray[$source['amountField']] ?? 0.0);
				$rate = (float)($itemArray['markupRateApplied'] ?? 0.0);
				if ($cost <= 0.0) {
					continue;
				}

				$totalCost += $cost;
				$totalWeighted += ($cost * $rate);
			}//end foreach
		}//end foreach

		if ($totalCost <= 0.0) {
			return null;
		}

		return ($totalWeighted / $totalCost);
	}//end weightedAverageMarkupRate()

	/**
	 * Check whether the named GLTransaction has been marked reversed per
	 * T1 REQ-GL-004. Returns false when the transaction cannot be located
	 * (fail-closed deny).
	 *
	 * @param string $glTxnId The GLTransaction id to inspect.
	 *
	 * @return bool True when the GL transaction is reversed.
	 */
	private function isGlTransactionReversed(string $glTxnId): bool {
		$register = $this->getRegisterSlug();

		$txn = $this->objectService
			->setRegister($register)
			->setSchema('GLTransaction')
			->find($glTxnId);

		if ($txn === null) {
			return false;
		}

		// ADR-084: see the note in the mixed-mode check above — `(array)$txn` cast
		// the ENTITY, so `status`/`isReversed` were never present and this guard
		// read every transaction as neither posted nor reversed.
		$txnArray = (array)$txn->jsonSerialize();

		$status = ($txnArray['status'] ?? null);
		$reversed = ($txnArray['isReversed'] ?? null);

		if ($reversed === true) {
			return true;
		}

		if (is_string($status) === true && in_array($status, ['reversed', 'voided'], true) === true) {
			return true;
		}

		return false;
	}//end isGlTransactionReversed()
}//end class
