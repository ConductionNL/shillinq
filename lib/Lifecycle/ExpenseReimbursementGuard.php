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

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

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
	 * The claim's line-item kinds, in the order they are read: the claim
	 * field listing their ids and the item field holding their cost.
	 *
	 * @var array<string, array{ids:string, amount:string}>
	 */
	private const ITEM_SOURCES = [
		'Receipt' => ['ids' => 'receiptIds', 'amount' => 'amountInBaseCurrency'],
		'MileageEntry' => ['ids' => 'mileageIds', 'amount' => 'totalAmount'],
		'PerDiem' => ['ids' => 'perDiemIds', 'amount' => 'allowanceAmount'],
	];

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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
			if ($this->claimModeIsValid(claim: $claim) === false) {
				return false;
			}

			return $this->allItemsMatchSettlementMode(claim: $claim, claimMode: (string)$claim['settlementMode']);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requireSettlementModeConsistency failed — denying submit (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireSettlementModeConsistency()

	/**
	 * Whether the claim carries a settlementMode, and a known one
	 * (reimbursable | pass-through); a refusal is logged (REQ-ERP-003).
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return bool
	 */
	private function claimModeIsValid(array $claim): bool {
		$claimMode = ($claim['settlementMode'] ?? null);
		if ($claimMode === null || $claimMode === '') {
			return $this->refuse(
				message: 'ExpenseReimbursementGuard: claim has no settlementMode — denying submit (REQ-ERP-003)',
				context: ['claimId' => ($claim['id'] ?? 'unknown')]
			);
		}

		if (in_array($claimMode, ['reimbursable', 'pass-through'], true) === false) {
			return $this->refuse(
				message: 'ExpenseReimbursementGuard: claim has invalid settlementMode — denying submit',
				context: [
					'claimId' => ($claim['id'] ?? 'unknown'),
					'settlementMode' => $claimMode,
				]
			);
		}

		return true;

	}//end claimModeIsValid()

	/**
	 * Log a refused transition and refuse it.
	 *
	 * @param string               $message The refusal log line.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return bool Always false.
	 */
	private function refuse(string $message, array $context): bool {
		$this->logger->info($message, $context);
		return false;

	}//end refuse()

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
			$threshold = $this->markupThreshold(claim: $claim);
			if ($threshold === null) {
				return true;
			}

			return $this->markupBelowThreshold(claim: $claim, threshold: $threshold);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseReimbursementGuard: requireMarkupApprovalIfThreshold failed — denying approve (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireMarkupApprovalIfThreshold()

	/**
	 * The markup-approval threshold that applies to the claim, or null: only
	 * a pass-through claim with an explicit policy declaring one is gated
	 * (no pass-through markup, or admin defaults, need no extra gate).
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return float|null
	 */
	private function markupThreshold(array $claim): float|null {
		$policyId = ($claim['reimbursementPolicyId'] ?? null);
		if (($claim['settlementMode'] ?? null) !== 'pass-through' || $policyId === null || $policyId === '') {
			return null;
		}

		return $this->getMarkupApprovalThresholdForPolicy(policyId: (string)$policyId);

	}//end markupThreshold()

	/**
	 * Whether the claim's pass-through markup stays below the threshold; at
	 * or above it the standard approve is denied, deferring to OR's
	 * approval-workflow extra-approver gate (REQ-ERP-006).
	 *
	 * @param array<string, mixed> $claim     ExpenseClaimEntry object array.
	 * @param float                $threshold The policy threshold.
	 *
	 * @return bool
	 */
	private function markupBelowThreshold(array $claim, float $threshold): bool {
		$totalPassThroughAmount = (float)($claim['totalPassThroughAmount'] ?? 0.0);
		$markupAmount = $this->computePassThroughMarkupAmount(claim: $claim, totalPassThrough: $totalPassThroughAmount);
		if ($markupAmount < $threshold) {
			return true;
		}

		return $this->refuse(
			message: 'ExpenseReimbursementGuard: pass-through markup meets/exceeds policy threshold — '
			. 'denying standard approve, deferring to OR approval-workflow extra-approver gate (REQ-ERP-006)',
			context: [
				'claimId' => ($claim['id'] ?? 'unknown'),
				'policyId' => ($claim['reimbursementPolicyId'] ?? null),
				'markupAmount' => $markupAmount,
				'threshold' => $threshold,
			]
		);

	}//end markupBelowThreshold()

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
	 * settlementMode are rejected (mixed-mode claim); pass-through items need
	 * a linkedCustomerId (REQ-ERP-002). The first refusal stops the lookups.
	 *
	 * @param array<string, mixed> $claim     ExpenseClaimEntry object array.
	 * @param string               $claimMode The claim-level settlementMode.
	 *
	 * @return bool True when all items match (or inherit) the claim mode.
	 */
	private function allItemsMatchSettlementMode(array $claim, string $claimMode): bool {
		$claimId = (string)($claim['id'] ?? '');
		foreach ($this->claimItems(claim: $claim) as $entry) {
			$refusal = ($this->modeRefusal(entry: $entry, claimMode: $claimMode) ?? $this->customerRefusal(entry: $entry, claimMode: $claimMode));
			if ($refusal !== null) {
				return $this->refuse(message: $refusal['message'], context: ['claimId' => $claimId] + $refusal['context']);
			}
		}

		return true;
	}//end allItemsMatchSettlementMode()

	/**
	 * The refusal for an item whose own settlementMode diverges from the
	 * claim's, or null.
	 *
	 * @param array{schema:string,id:string,item:array<string,mixed>,amountField:string} $entry     A claim item.
	 * @param string                                                                     $claimMode The claim-level settlementMode.
	 *
	 * @return array{message:string,context:array<string,mixed>}|null
	 */
	private function modeRefusal(array $entry, string $claimMode): array|null {
		$itemMode = ($entry['item']['settlementMode'] ?? null);
		if ($itemMode === null || $itemMode === '' || $itemMode === $claimMode) {
			return null;
		}

		return [
			'message' => 'ExpenseReimbursementGuard: mixed-mode claim rejected (REQ-ERP-003)',
			'context' => [
				'itemId' => $entry['id'],
				'schema' => $entry['schema'],
				'claimMode' => $claimMode,
				'itemMode' => $itemMode,
			],
		];
	}//end modeRefusal()

	/**
	 * The refusal for a pass-through claim's item without a linkedCustomerId
	 * (REQ-ERP-002 validation), or null.
	 *
	 * @param array{schema:string,id:string,item:array<string,mixed>,amountField:string} $entry     A claim item.
	 * @param string                                                                     $claimMode The claim-level settlementMode.
	 *
	 * @return array{message:string,context:array<string,mixed>}|null
	 */
	private function customerRefusal(array $entry, string $claimMode): array|null {
		$linkedCustomerId = ($entry['item']['linkedCustomerId'] ?? null);
		if ($claimMode !== 'pass-through' || ($linkedCustomerId !== null && $linkedCustomerId !== '')) {
			return null;
		}

		return [
			'message' => 'ExpenseReimbursementGuard: pass-through item missing linkedCustomerId (REQ-ERP-002)',
			'context' => [
				'itemId' => $entry['id'],
				'schema' => $entry['schema'],
			],
		];
	}//end customerRefusal()

	/**
	 * The claim's resolvable line items — receipts, then mileage entries,
	 * then per-diems, each in id order — read lazily, so a caller that stops
	 * early stops the lookups. Empty and unknown ids are skipped.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return \Generator<int, array{schema:string,id:string,item:array<string,mixed>,amountField:string}>
	 */
	private function claimItems(array $claim): \Generator {
		$register = $this->getRegisterSlug();
		foreach (self::ITEM_SOURCES as $schema => $source) {
			foreach ((array)($claim[$source['ids']] ?? []) as $itemId) {
				if ((string)$itemId === '') {
					continue;
				}

				$item = $this->claimItem(scoped: $this->objectService->setRegister($register)->setSchema($schema), id: (string)$itemId);
				if ($item !== null) {
					yield ['schema' => $schema, 'id' => (string)$itemId, 'item' => $item, 'amountField' => $source['amount']];
				}
			}
		}
	}//end claimItems()

	/**
	 * One line item's stored payload, or null for an unknown id.
	 *
	 * ADR-084: find() returns an ObjectEntityInterface, so the payload is read
	 * through jsonSerialize(); `(array)$item` would cast the ENTITY — yielding
	 * its (mangled, private) property names rather than the stored payload.
	 *
	 * @param object $scoped The object service scoped to the item's schema.
	 * @param string $id     The item id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function claimItem(object $scoped, string $id): array|null {
		$item = ObjectIdentifier::findScoped(scoped: $scoped, id: $id);
		if ($item === null) {
			return null;
		}

		return (array)$item->jsonSerialize();
	}//end claimItem()

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
	private function weightedAverageMarkupRate(array $claim): float|null {
		$totalCost = 0.0;
		$totalWeighted = 0.0;
		foreach ($this->claimItems(claim: $claim) as $entry) {
			$cost = $this->passThroughCost(entry: $entry);
			if ($cost > 0.0) {
				$totalCost += $cost;
				$totalWeighted += ($cost * (float)($entry['item']['markupRateApplied'] ?? 0.0));
			}
		}

		if ($totalCost <= 0.0) {
			return null;
		}

		return ($totalWeighted / $totalCost);
	}//end weightedAverageMarkupRate()

	/**
	 * A pass-through item's cost in base currency, or 0.0 for any other item.
	 *
	 * @param array{schema:string,id:string,item:array<string,mixed>,amountField:string} $entry A claim item.
	 *
	 * @return float
	 */
	private function passThroughCost(array $entry): float {
		if (($entry['item']['settlementMode'] ?? null) !== 'pass-through') {
			return 0.0;
		}

		return (float)($entry['item'][$entry['amountField']] ?? 0.0);
	}//end passThroughCost()

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

		$txn = ObjectIdentifier::findScoped(
			scoped: $this->objectService
				->setRegister($register)
				->setSchema('GLTransaction'),
			id: $glTxnId
		);

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
