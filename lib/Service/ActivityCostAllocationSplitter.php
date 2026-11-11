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
	public function resolveRule(array $candidates, string $postingDate): array|null {
		$posting = $this->parseDate(value: $postingDate);
		if ($posting === null) {
			return null;
		}

		foreach ($candidates as $rule) {
			if (is_array($rule) === true && $this->ruleCovers(rule: $rule, posting: $posting) === true) {
				return $rule;
			}
		}

		return null;
	}//end resolveRule()

	/**
	 * Whether a rule has started on the posting date and not yet ended.
	 *
	 * @param array<string,mixed> $rule    A candidate OverheadDistributionRule.
	 * @param DateTimeImmutable   $posting The posting date.
	 *
	 * @return bool
	 */
	private function ruleCovers(array $rule, DateTimeImmutable $posting): bool {
		$from = (string)($rule['effectiveFrom'] ?? $rule['validFrom'] ?? '');
		$to = (string)($rule['effectiveTo'] ?? $rule['validTo'] ?? '');

		return $this->hasStarted(from: $from, posting: $posting) === true
			&& $this->hasNotEnded(to: $to, posting: $posting) === true;
	}//end ruleCovers()

	/**
	 * Whether a rule's from-date lies on or before the posting date. No
	 * from-date counts as started; an unparseable one never does.
	 *
	 * @param string            $from    The rule's from-date, or ''.
	 * @param DateTimeImmutable $posting The posting date.
	 *
	 * @return bool
	 */
	private function hasStarted(string $from, DateTimeImmutable $posting): bool {
		if ($from === '') {
			return true;
		}

		$start = $this->parseDate(value: $from);
		return $start !== null && $start <= $posting;
	}//end hasStarted()

	/**
	 * Whether a rule's to-date lies on or after the posting date. No to-date,
	 * or an unparseable one, leaves the rule open-ended.
	 *
	 * @param string            $to      The rule's to-date, or ''.
	 * @param DateTimeImmutable $posting The posting date.
	 *
	 * @return bool
	 */
	private function hasNotEnded(string $to, DateTimeImmutable $posting): bool {
		$end = $this->parseDate(value: $to);
		return $end === null || $end >= $posting;
	}//end hasNotEnded()

	/**
	 * Parse a date, or null when it is empty or unparseable.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate(string $value): DateTimeImmutable|null {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}
	}//end parseDate()

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
		$rawSplits = (array)($rule['splits'] ?? $rule['verdeelsleutel'] ?? []);
		if ($rawSplits === []) {
			throw new InvalidArgumentException('OverheadDistributionRule has no splits/verdeelsleutel array');
		}

		$splits = array_values(array_filter($rawSplits, 'is_array'));
		$ratios = array_map(fn (array $split): float => $this->clampedRatio(split: $split), $splits);
		$signedCents = $this->signedPartCents(ratios: $ratios, originalCents: $originalCents);

		return array_map(
			fn (array $split, int $cents): array => $this->splitRecord(split: $split, signedCents: $cents),
			$splits,
			$signedCents
		);
	}//end calculateSplits()

	/**
	 * A split's ratio, clamped to 0..1.
	 *
	 * @param array<string,mixed> $split One split of the rule.
	 *
	 * @return float
	 */
	private function clampedRatio(array $split): float {
		$ratio = (float)($split['ratio'] ?? 0);
		if ($ratio < 0.0) {
			return 0.0;
		}

		if ($ratio > 1.0) {
			return 1.0;
		}

		return $ratio;
	}//end clampedRatio()

	/**
	 * Each ratio's share of the original amount in cents (half-even rounding
	 * of the absolute amount), with the rounding drift reconciled and the
	 * original sign applied.
	 *
	 * @param array<int,float> $ratios        The clamped ratios.
	 * @param int              $originalCents The signed original amount in cents.
	 *
	 * @return array<int,int>
	 */
	private function signedPartCents(array $ratios, int $originalCents): array {
		$absCents = abs($originalCents);
		$parts = array_map(fn (float $ratio): int => (int)round($absCents * $ratio, 0, PHP_ROUND_HALF_EVEN), $ratios);
		$parts = $this->withDriftOnLargest(parts: $parts, totalCents: $absCents);

		$sign = 1;
		if ($originalCents < 0) {
			$sign = -1;
		}

		return array_map(fn (int $part): int => ($part * $sign), $parts);
	}//end signedPartCents()

	/**
	 * Move any rounding drift onto the first largest part, so the parts add up
	 * to the total.
	 *
	 * @param array<int,int> $parts      The rounded parts in cents.
	 * @param int            $totalCents The amount they must add up to.
	 *
	 * @return array<int,int>
	 */
	private function withDriftOnLargest(array $parts, int $totalCents): array {
		$allocated = array_sum($parts);
		if ($allocated === $totalCents || $parts === []) {
			return $parts;
		}

		$parts[$this->firstLargestIndex(parts: $parts)] += ($totalCents - $allocated);
		return $parts;
	}//end withDriftOnLargest()

	/**
	 * The index of the first part larger than every part before it and than
	 * zero, or 0 when no part is above zero.
	 *
	 * @param array<int,int> $parts The rounded parts in cents.
	 *
	 * @return int
	 */
	private function firstLargestIndex(array $parts): int {
		$largestIndex = 0;
		$largestCents = 0;
		foreach ($parts as $index => $cents) {
			if ($cents > $largestCents) {
				$largestCents = $cents;
				$largestIndex = $index;
			}
		}

		return $largestIndex;
	}//end firstLargestIndex()

	/**
	 * The split record for one split and its signed amount.
	 *
	 * @param array<string,mixed> $split       One split of the rule.
	 * @param int                 $signedCents Its signed share in cents.
	 *
	 * @return array<string,mixed>
	 */
	private function splitRecord(array $split, int $signedCents): array {
		return [
			'costObject' => (string)($split['costObject'] ?? $split['costObjectCode'] ?? ''),
			'ratio' => $this->clampedRatio(split: $split),
			'amount' => $this->fromCents(cents: $signedCents),
			'generalLedger' => ($split['generalLedger'] ?? $split['glAccount'] ?? null),
			'dimension' => (string)($split['dimension'] ?? 'MO'),
		];
	}//end splitRecord()

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
		$approval = self::overrideApproval(input: $input);
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
			'handmatigeOverride' => $approval + [
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
	 * The 4-eyes approval of an override: exactly 2 distinct approver user-ids
	 * and a non-empty motivation.
	 *
	 * @param array<string,mixed> $input Override inputs.
	 *
	 * @return array{approvedBy: list<string>, reason: string}
	 *
	 * @throws InvalidArgumentException When approvedBy is not exactly 2 distinct user-ids, or the reason is empty.
	 */
	private static function overrideApproval(array $input): array {
		$approvedBy = (array)($input['approvedBy'] ?? []);
		$unique = array_values(array_unique(array_map('strval', $approvedBy)));

		if (count($unique) !== 2) {
			throw new InvalidArgumentException('Handmatige override requires exactly 2 distinct approver user-ids');
		}

		$reason = (string)($input['reason'] ?? '');
		if ($reason === '') {
			throw new InvalidArgumentException('Handmatige override requires a non-empty reason');
		}

		return ['approvedBy' => $unique, 'reason' => $reason];

	}//end overrideApproval()

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
