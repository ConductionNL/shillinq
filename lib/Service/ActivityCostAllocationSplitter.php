<?php

/**
 * Activity Cost Allocation Splitter (WMO transaction splitting)
 *
 * Pure-logic splitter for `ActivityCostAllocation` records (REQ-WMO-003). Takes
 * a posted JournalEntry, the matching CommercialActivity record, and the
 * geldende OverheadDistributionRule for the posting date, and returns the
 * split structure: original amount split into PUBL (publieke) and MO
 * (markt/commerciële) dimensions per the rule's ratios.
 *
 * Handmatige overrides are supported through `composeOverride()` which mints
 * a replacement allocation marked `automatischToegepast=false` with the 2-eye
 * approval payload, while marking the original as `status=overridden`.
 *
 * All money arithmetic uses integer cents to avoid IEEE-754 drift.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Side-effect-free splitter for ActivityCostAllocation records (REQ-WMO-003).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-10
 */
class ActivityCostAllocationSplitter {
	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100, 0, PHP_ROUND_HALF_EVEN);
	}//end toCents()

	/**
	 * Convert integer cents back to a float.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Amount in EUR.
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Resolve the geldende OverheadDistributionRule for a posting date (REQ-WMO-003).
	 *
	 * The caller supplies a list of candidate rules ordered chronologically.
	 * The first rule whose effectiveFrom <= postingDate and (effectiveTo is null
	 * or >= postingDate) wins.
	 *
	 * @param array<int,array<string,mixed>> $candidates Candidate OverheadDistributionRules.
	 * @param string $postingDate ISO date (YYYY-MM-DD) of the journal entry.
	 *
	 * @return array<string,mixed>|null The geldende rule or null when no rule matches.
	 */
	public function resolveRule(array $candidates, string $postingDate): ?array {
		if ($postingDate === '') {
			return null;
		}

		try {
			$posting = new DateTimeImmutable($postingDate);
		} catch (\Throwable) {
			return null;
		}

		foreach ($candidates as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$from = (string)($rule['effectiveFrom'] ?? $rule['validFrom'] ?? '');
			$to = (string)($rule['effectiveTo'] ?? $rule['validTo'] ?? '');

			try {
				if ($from !== '' && new DateTimeImmutable($from) > $posting) {
					continue;
				}
			} catch (\Throwable) {
				continue;
			}

			if ($to !== '') {
				try {
					if (new DateTimeImmutable($to) < $posting) {
						continue;
					}
				} catch (\Throwable) {
					// Unparseable to-date — treat rule as open-ended.
				}
			}

			return $rule;
		}//end foreach

		return null;
	}//end resolveRule()

	/**
	 * Split an original amount across PUBL + MO dimensions per a rule's ratios (REQ-WMO-003).
	 *
	 * The rule MUST contain `splits` array with `dimensie` (PUBL or MO),
	 * `ratio` (0..1), and `kostendrager` (destination drager). Sum of ratios
	 * SHOULD equal 1.0; the splitter reconciles any rounding drift onto the
	 * largest split to preserve balance.
	 *
	 * @param float $originalAmount Original transaction amount in EUR (signed).
	 * @param array<string,mixed> $rule The geldende OverheadDistributionRule.
	 *
	 * @return array<int,array<string,mixed>> Balanced split records.
	 */
	public function calculateSplits(float $originalAmount, array $rule): array {
		$originalCents = $this->toCents(amount: $originalAmount);
		$sign = 1;
		if ($originalCents < 0) {
			$sign = -1;
		}

		$absCents = abs($originalCents);

		$rawSplits = (array)($rule['splits'] ?? $rule['verdeelsleutel'] ?? []);
		if ($rawSplits === []) {
			throw new InvalidArgumentException('OverheadDistributionRule has no splits/verdeelsleutel array');
		}

		$records = [];
		$allocated = 0;
		$largestIndex = 0;
		$largestCents = 0;

		$idx = 0;
		foreach ($rawSplits as $split) {
			if (is_array($split) === false) {
				continue;
			}

			$ratio = (float)($split['ratio'] ?? 0);
			if ($ratio < 0.0) {
				$ratio = 0.0;
			}

			if ($ratio > 1.0) {
				$ratio = 1.0;
			}

			$partCents = (int)round($absCents * $ratio, 0, PHP_ROUND_HALF_EVEN);

			$records[] = [
				'costObject' => (string)($split['costObject'] ?? $split['costObjectCode'] ?? ''),
				'ratio' => $ratio,
				'amount' => $this->fromCents(cents: ($partCents * $sign)),
				'generalLedger' => ($split['generalLedger'] ?? $split['glAccount'] ?? null),
				'dimension' => (string)($split['dimension'] ?? 'MO'),
			];

			$allocated += $partCents;
			if ($partCents > $largestCents) {
				$largestCents = $partCents;
				$largestIndex = $idx;
			}

			$idx++;
		}//end foreach

		// Reconcile rounding drift onto the largest split.
		if ($allocated !== $absCents && $records !== []) {
			$drift = ($absCents - $allocated);
			$largest = $records[$largestIndex];
			$largestAmountCents = $this->toCents(amount: $largest['amount']) + ($drift * $sign);
			$records[$largestIndex]['amount'] = $this->fromCents(cents: $largestAmountCents);
		}

		return $records;
	}//end calculateSplits()

	/**
	 * Compose an `ActivityCostAllocation` record for a journal entry (REQ-WMO-003).
	 *
	 * @param array<string,mixed> $input Composition inputs (journalEntryId,
	 *                                   commercialActivityId, originalAmount, rule,
	 *                                   postingDate, administrationId, glLineId,
	 *                                   materialised).
	 *
	 * @return array<string,mixed> An allocation record matching the schema.
	 */
	public function compose(array $input): array {
		$splits = $this->calculateSplits(
			originalAmount: (float)$input['originalAmount'],
			rule: (array)$input['rule']
		);

		return [
			'journalEntryId' => (string)$input['journalEntryId'],
			'commercialActivityId' => (string)$input['commercialActivityId'],
			'glLineId' => ($input['glLineId'] ?? null),
			'originalAmount' => (float)$input['originalAmount'],
			'splits' => $splits,
			'allocationKey' => (string)($input['rule']['id'] ?? ''),
			'automaticApplied' => true,
			'handmatigeOverride' => null,
			'postingDate' => (string)$input['postingDate'],
			'materialised' => (bool)($input['materialised'] ?? false),
			'administrationId' => (string)$input['administrationId'],
			'status' => 'active',
		];

	}//end compose()

	/**
	 * Compose a handmatige-override replacement allocation (REQ-WMO-003 §override).
	 *
	 * Requires exactly 2 approver user-ids (4-eyes principle), a motivation, and
	 * the original allocation's id. The original SHALL be marked
	 * `status=overridden` separately by the caller.
	 *
	 * @param array<string,mixed> $input Override inputs (originalAllocation,
	 *                                   approvedBy, reason, newSplits).
	 *
	 * @return array<string,mixed> Replacement allocation record.
	 *
	 * @throws InvalidArgumentException When approvedBy is not exactly 2 user-ids.
	 */
	public function composeOverride(array $input): array {
		$approvedBy = (array)($input['approvedBy'] ?? []);
		$unique = array_values(array_unique(array_map('strval', $approvedBy)));

		if (count($unique) !== 2) {
			throw new InvalidArgumentException('Handmatige override requires exactly 2 distinct approver user-ids');
		}

		$reason = (string)($input['reason'] ?? '');
		if ($reason === '') {
			throw new InvalidArgumentException('Handmatige override requires a non-empty reason');
		}

		$original = (array)$input['originalAllocation'];
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);
		$originalId = (string)($original['id'] ?? $original['_id'] ?? '');

		return [
			'journalEntryId' => (string)($original['journalEntryId'] ?? ''),
			'commercialActivityId' => (string)($original['commercialActivityId'] ?? ''),
			'glLineId' => ($original['glLineId'] ?? null),
			'originalAmount' => (float)($original['originalAmount'] ?? 0),
			'splits' => (array)($input['newSplits'] ?? []),
			'allocationKey' => (string)($original['allocationKey'] ?? ''),
			'automaticApplied' => false,
			'handmatigeOverride' => [
				'approvedBy' => $unique,
				'reason' => $reason,
				'timestamp' => $now,
				'replacesId' => $originalId,
			],
			'postingDate' => (string)($original['postingDate'] ?? ''),
			'materialised' => (bool)($original['materialised'] ?? false),
			'administrationId' => (string)($original['administrationId'] ?? ''),
			'status' => 'active',
		];

	}//end composeOverride()

	/**
	 * Materialise splits as balanced GL lines (REQ-WMO-003 §materialise mode).
	 *
	 * Emits PUBL + MO balanced postings for each split: e.g. Dr 4431 €11.8k PUBL +
	 * Dr 4432 €6.6k MO totalling the original. The caller posts these as the
	 * additional GL lines on the journal entry.
	 *
	 * @param array<int,array<string,mixed>> $splits The allocation splits to materialise.
	 * @param string $glAccountClass GL account prefix for the kostendrager (e.g. '443').
	 *
	 * @return array<int,array<string,mixed>> Balanced GL line entries.
	 */
	public function materialiseSplits(array $splits, string $glAccountClass = '443'): array {
		$entries = [];

		foreach ($splits as $split) {
			if (is_array($split) === false) {
				continue;
			}

			$amount = (float)($split['amount'] ?? 0);
			if ($amount === 0.0) {
				continue;
			}

			$side = 'credit';
			if ($amount >= 0) {
				$side = 'debit';
			}

			$entries[] = [
				'generalLedger' => (string)($split['generalLedger'] ?? ($glAccountClass . ((string)($split['dimension'] ?? 'MO')))),
				'amount' => $amount,
				'costObject' => (string)($split['costObject'] ?? ''),
				'dimension' => (string)($split['dimension'] ?? 'MO'),
				'side' => $side,
			];
		}//end foreach

		return $entries;
	}//end materialiseSplits()
}//end class
