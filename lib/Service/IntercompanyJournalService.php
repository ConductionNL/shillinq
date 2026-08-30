<?php

/**
 * Intercompany Journal Service
 *
 * Pure-logic helper for intercompany-journaalpost mirroring and reconciliation
 * (REQ-MA-004). A single conceptual intercompany transaction (e.g. a management
 * fee from Werk B.V. to Beheer B.V.) is booked as two self-contained journal
 * entries — one in each administration — linked through an IntercompanyJournalEntry
 * tracking record. This service computes the mirrored counter-side, the
 * reconciliation variance (afwijking_bedrag) between the two sides, and validates
 * the status transitions concept → gekoppeld → bevestigd_beide → eliminatie_geboekt.
 *
 * All arithmetic is done in integer cents to avoid binary-float rounding error.
 * The service is storage-agnostic and side-effect-free so it is unit-testable
 * without OpenRegister; the controller layer persists the records via the real
 * ObjectService API.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Computes intercompany mirroring + reconciliation and validates the status flow.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class IntercompanyJournalService {
	/**
	 * Allowed IntercompanyJournalEntry status transitions (REQ-MA-004).
	 *
	 * Mirrors the x-openregister-lifecycle declared on the IntercompanyJournalEntry
	 * schema: from-state => list of permitted to-states.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TRANSITIONS = [
		'draft' => ['gekoppeld'],
		'gekoppeld' => ['bevestigd_beide', 'draft'],
		'bevestigd_beide' => ['eliminatie_geboekt', 'draft'],
		'eliminatie_geboekt' => [],
	];

	/**
	 * Convert a decimal amount to integer cents (round half-up).
	 *
	 * @param float|int|string $amount The amount in major currency units.
	 *
	 * @return int Amount in cents.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function toCents(float|int|string $amount): int {
		return (int)round(((float)$amount) * 100);
	}//end toCents()

	/**
	 * Build the destination (mirrored) side of an intercompany transaction (REQ-MA-004).
	 *
	 * Given the source administration's booking, the mirror swaps source and
	 * destination administrations and inverts the debit/credit perspective: where
	 * the source debits an expense and credits a payable to the counterparty, the
	 * destination debits a receivable from the counterparty and credits income.
	 * The returned array describes the mirrored IntercompanyJournalEntry side; the
	 * caller persists the actual GLTransaction.
	 *
	 * @param array<string,mixed> $source The source-side intercompany entry data.
	 *
	 * @return array<string,mixed> The mirrored destination-side entry data.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function buildMirror(array $source): array {
		$amountCents = $this->toCents(amount: ($source['amount'] ?? 0));

		return [
			'intercompanyNumber' => (string)($source['intercompanyNumber'] ?? ''),
			'date' => (string)($source['date'] ?? ''),
			'kind' => (string)($source['kind'] ?? 'other'),
			// Swap perspective: the mirror's source is the original destination.
			'sourceAdministrationId' => (string)($source['destinationAdministrationId'] ?? ''),
			'destinationAdministrationId' => (string)($source['sourceAdministrationId'] ?? ''),
			'amount' => ($amountCents / 100),
			'currency' => (string)($source['currency'] ?? 'EUR'),
			'exchangeRate' => (float)($source['exchangeRate'] ?? 1.0),
			'vatTreatment' => (string)($source['vatTreatment'] ?? 'standard'),
			'eliminateOnConsolidation' => (bool)($source['eliminateOnConsolidation'] ?? false),
			'eliminationAccount' => ($source['eliminationAccount'] ?? null),
			'status' => 'gekoppeld',
			'varianceAmount' => 0.0,
		];

	}//end buildMirror()

	/**
	 * Compute the reconciliation variance between the two intercompany sides (REQ-MA-004).
	 *
	 * The variance (afwijking) is the absolute difference between the source and
	 * mirrored amounts, expressed in major currency units. A non-zero variance must
	 * flag the pair for manual review.
	 *
	 * @param float|int|string $sourceAmount The booked source amount.
	 * @param float|int|string $destinationAmount The booked destination amount.
	 *
	 * @return float The variance in major currency units (>= 0).
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function reconcileVariance(float|int|string $sourceAmount, float|int|string $destinationAmount): float {
		$diffCents = abs(($this->toCents(amount: $sourceAmount) - $this->toCents(amount: $destinationAmount)));
		return ($diffCents / 100);
	}//end reconcileVariance()

	/**
	 * Whether the source and destination sides balance within a one-cent tolerance.
	 *
	 * @param float|int|string $sourceAmount The booked source amount.
	 * @param float|int|string $destinationAmount The booked destination amount.
	 *
	 * @return bool True when the two sides reconcile.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function isBalanced(float|int|string $sourceAmount, float|int|string $destinationAmount): bool {
		return ($this->toCents(amount: $sourceAmount) === $this->toCents(amount: $destinationAmount));
	}//end isBalanced()

	/**
	 * Validate an IntercompanyJournalEntry status transition (REQ-MA-004).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested target status.
	 *
	 * @return bool True when the transition is permitted by the lifecycle.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		if ($from === $to) {
			return true;
		}

		return in_array(needle: $to, haystack: (self::TRANSITIONS[$from] ?? []), strict: true);
	}//end isTransitionAllowed()

	/**
	 * Resolve the status after an edit on one unconfirmed side (REQ-MA-004).
	 *
	 * When either side is edited while the pair is bevestigd_beide, the status
	 * returns to concept and the counterparty must re-confirm. Already-eliminated
	 * pairs are locked and unaffected.
	 *
	 * @param string $currentStatus The current intercompany status.
	 *
	 * @return string The status after the edit.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function statusAfterEdit(string $currentStatus): string {
		if ($currentStatus === 'eliminatie_geboekt') {
			return 'eliminatie_geboekt';
		}

		if ($currentStatus === 'bevestigd_beide' || $currentStatus === 'gekoppeld') {
			$next = 'draft';
		} else {
			$next = $currentStatus;
		}

		if ($this->isTransitionAllowed(from: $currentStatus, to: $next) === false) {
			return $currentStatus;
		}

		return $next;
	}//end statusAfterEdit()
}//end class
