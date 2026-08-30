<?php

/**
 * Booking Cancellation Service
 *
 * Pure business-logic seam for booking cancellation: late-fee bracket matching,
 * refund-amount computation in integer cents, cancellation validation, and the
 * cancellation mutation that snapshots cancelledAt / cancelledReason / refundAmount
 * / refundStatus onto an appointment. All monetary maths is done in integer cents
 * (design D3/D4) and all temporal maths in UTC (design D8) so the result is
 * deterministic and free of IEEE-754 rounding error.
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
 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Computes refunds and applies cancellations against an appointment's snapshot
 * `appliedPolicy` (design D2). The service is intentionally OR-agnostic: it
 * operates on plain appointment/policy arrays so it can be unit-tested in full
 * isolation and reused from any caller (admin UI, customer portal, REST API).
 *
 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
 */
class CancellationService {
	/**
	 * Allowed cancellation reasons (REQ-BCR-002, design D5). Immutable enum.
	 *
	 * @var array<string>
	 */
	public const REASONS = [
		'customer_request',
		'double_booked',
		'schedule_conflict',
		'payment_issue',
		'no_show',
		'other',
	];

	/**
	 * Refund is queued and awaiting processing (REQ-BCR-006, design D6).
	 *
	 * @var string
	 */
	public const REFUND_STATUS_PENDING = 'pending';

	/**
	 * Refund has been processed by the payment system.
	 *
	 * @var string
	 */
	public const REFUND_STATUS_PROCESSED = 'processed';

	/**
	 * Refund attempt failed and needs operator attention.
	 *
	 * @var string
	 */
	public const REFUND_STATUS_FAILED = 'failed';

	/**
	 * Refund was cancelled before processing.
	 *
	 * @var string
	 */
	public const REFUND_STATUS_CANCELLED = 'cancelled';

	/**
	 * Construct the service.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for audit-trail diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the refund amount (in integer cents) for an appointment, given its
	 * snapshot `appliedPolicy` and `appointmentCost`.
	 *
	 * Algorithm (design D3/D4):
	 *  1. Resolve the whole number of days between the cancellation instant and
	 *     the appointment start, both in UTC (`floor` of the second-distance).
	 *  2. Select the matching late-fee bracket: the bracket with the largest
	 *     `daysBeforeStart` that is still `<=` the actual days until start. If no
	 *     bracket qualifies (cancelled later than every threshold) the most
	 *     punitive bracket — the one with the smallest `daysBeforeStart` — applies.
	 *  3. fee = percentage of cost, or a fixed amount in cents.
	 *  4. refund = cost - fee, floored at 0 (never negative, never > cost).
	 *
	 * @param array<string, mixed> $appointment Appointment array. Must carry
	 *                                          `appointmentCost` (int
	 *                                          cents), `startTime`
	 *                                          (ISO-8601), and
	 *                                          `appliedPolicy` (snapshot).
	 * @param DateTimeImmutable|null $cancelledAt Cancellation instant; defaults
	 *                                            to "now" in UTC.
	 *
	 * @return int Refund amount in integer cents (0 <= refund <= appointmentCost).
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
	 */
	public function calculateRefund(array $appointment, ?DateTimeImmutable $cancelledAt = null): int {
		$cost = (int)($appointment['appointmentCost'] ?? 0);
		if ($cost <= 0) {
			return 0;
		}

		$policy = ($appointment['appliedPolicy'] ?? []);
		$brackets = ($policy['lateFeeBrackets'] ?? []);
		if (is_array($brackets) === false || $brackets === []) {
			// No fee structure snapshotted → full refund (free cancellation).
			return $cost;
		}

		$cancelledAt = ($cancelledAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$start = $this->parseUtc(value: (string)($appointment['startTime'] ?? ''));
		if ($start === null) {
			// No resolvable start time → refuse to compute a fee, full refund.
			return $cost;
		}

		$daysUntilStart = $this->wholeDaysBetween(from: $cancelledAt, to: $start);
		$bracket = $this->matchBracket(brackets: $brackets, daysUntilStart: $daysUntilStart);
		if ($bracket === null) {
			return $cost;
		}

		$feeCents = $this->bracketFeeCents(bracket: $bracket, costCents: $cost);

		$refund = ($cost - $feeCents);
		if ($refund < 0) {
			$refund = 0;
		}

		if ($refund > $cost) {
			$refund = $cost;
		}

		return $refund;
	}//end calculateRefund()

	/**
	 * Validate whether an appointment may be cancelled.
	 *
	 * Fails (allowed=false) when the appointment is already cancelled
	 * (`cancelledAt` is set) — the caller maps this to HTTP 409 (REQ-BCR-008) —
	 * or when the supplied reason is not in the allowed enum (REQ-BCR-002).
	 *
	 * @param array<string, mixed> $appointment Appointment array.
	 * @param string|null $reason Optional cancellation reason to validate.
	 *
	 * @return array{allowed: bool, code: string, message: string} Validation result.
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-cannot-be-undone-immutability
	 */
	public function validateCancellation(array $appointment, ?string $reason = null): array {
		$cancelledAt = ($appointment['cancelledAt'] ?? null);
		if (empty($cancelledAt) === false) {
			return [
				'allowed' => false,
				'code' => 'already_cancelled',
				'message' => 'Appointment already cancelled at ' . ((string)$cancelledAt),
			];
		}

		if ($reason !== null && in_array($reason, self::REASONS, true) === false) {
			return [
				'allowed' => false,
				'code' => 'invalid_reason',
				'message' => 'Unknown cancellation reason: ' . $reason,
			];
		}

		return [
			'allowed' => true,
			'code' => 'ok',
			'message' => '',
		];

	}//end validateCancellation()

	/**
	 * Apply a cancellation to an appointment array and return the mutated copy.
	 *
	 * Sets `cancelledAt` (UTC ISO-8601), `cancelledReason`, `refundAmount`
	 * (via {@see calculateRefund()}), and `refundStatus = pending`. The input is
	 * NOT mutated in place (returns a new array) so callers can diff before/after
	 * for the audit trail (REQ-BCR-009). Throws when validation fails so callers
	 * never persist an inconsistent cancellation.
	 *
	 * @param array<string, mixed> $appointment Appointment array.
	 * @param string $reason Cancellation reason (must be in {@see REASONS}).
	 * @param DateTimeImmutable|null $cancelledAt Cancellation instant; defaults to now (UTC).
	 *
	 * @return array<string, mixed> The cancelled appointment array.
	 *
	 * @throws InvalidArgumentException When the appointment cannot be cancelled.
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
	 */
	public function initiateCancellation(
		array $appointment,
		string $reason,
		?DateTimeImmutable $cancelledAt = null,
	): array {
		$validation = $this->validateCancellation(appointment: $appointment, reason: $reason);
		if ($validation['allowed'] === false) {
			throw new InvalidArgumentException($validation['message']);
		}

		$cancelledAt = ($cancelledAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$refund = $this->calculateRefund(appointment: $appointment, cancelledAt: $cancelledAt);

		$cancelled = $appointment;
		$cancelled['cancelledAt'] = $cancelledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		$cancelled['cancelledReason'] = $reason;
		$cancelled['refundAmount'] = $refund;
		$cancelled['refundStatus'] = self::REFUND_STATUS_PENDING;

		$this->logger->info(
			'Shillinq: appointment cancelled',
			[
				'reason' => $reason,
				'refundAmount' => $refund,
				'cancelledAt' => $cancelled['cancelledAt'],
			]
		);

		return $cancelled;
	}//end initiateCancellation()

	/**
	 * Select the matching late-fee bracket for a given days-until-start distance.
	 *
	 * Returns the bracket with the largest `daysBeforeStart` that is `<=` the
	 * actual days until start (i.e. the most generous threshold the cancellation
	 * still clears). When the cancellation is later than every threshold, the
	 * bracket with the smallest `daysBeforeStart` (most punitive) applies.
	 *
	 * @param array<int, array<string, mixed>> $brackets Bracket list.
	 * @param int $daysUntilStart Whole days until start.
	 *
	 * @return array<string, mixed>|null The matching bracket, or null if none.
	 */
	private function matchBracket(array $brackets, int $daysUntilStart): ?array {
		// Sort descending by daysBeforeStart so the first qualifying bracket is the
		// largest threshold the cancellation clears (design D3).
		usort(
			$brackets,
			static fn (array $a, array $b): int => ((int)($b['daysBeforeStart'] ?? 0) <=> (int)($a['daysBeforeStart'] ?? 0))
		);

		foreach ($brackets as $bracket) {
			if ((int)($bracket['daysBeforeStart'] ?? 0) <= $daysUntilStart) {
				return $bracket;
			}
		}

		// Cancelled later than every threshold → the smallest-threshold bracket
		// (last after the descending sort) is the most punitive applicable one.
		if ($brackets === []) {
			return null;
		}

		return $brackets[(count($brackets) - 1)];
	}//end matchBracket()

	/**
	 * Resolve a bracket's fee in integer cents against an appointment cost.
	 *
	 * `percentage`: feeAmount is a 0-100 percentage of cost (rounded to nearest
	 * cent, half-up, Dutch banking convention). `fixed`: feeAmount is already in
	 * integer cents. The fee never exceeds the appointment cost.
	 *
	 * @param array<string, mixed> $bracket The matched bracket.
	 * @param int $costCents Appointment cost in integer cents.
	 *
	 * @return int Fee in integer cents (0 <= fee <= costCents).
	 */
	private function bracketFeeCents(array $bracket, int $costCents): int {
		$type = (string)($bracket['feeType'] ?? 'percentage');
		$amount = (int)($bracket['feeAmount'] ?? 0);
		if ($amount < 0) {
			$amount = 0;
		}

		if ($type === 'fixed') {
			$fee = $amount;
			if ($fee > $costCents) {
				$fee = $costCents;
			}

			return $fee;
		}

		// Percentage bracket: clamp to 100% then round half-up to the nearest
		// cent (design risk 3).
		if ($amount > 100) {
			$amount = 100;
		}

		$fee = (int)round(($costCents * $amount) / 100);
		if ($fee > $costCents) {
			$fee = $costCents;
		}

		return $fee;
	}//end bracketFeeCents()

	/**
	 * Whole-day distance from $from to $to, both coerced to UTC (design D8).
	 *
	 * Returns `floor((to - from) / 86400)` in seconds. Negative (cancellation
	 * after the start) is clamped to 0 so a past appointment matches the most
	 * punitive bracket rather than producing a negative day count.
	 *
	 * @param DateTimeImmutable $from Cancellation instant.
	 * @param DateTimeImmutable $to Appointment start.
	 *
	 * @return int Whole days until start (>= 0).
	 */
	private function wholeDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
		$utc = new DateTimeZone('UTC');
		$fromTs = $from->setTimezone($utc)->getTimestamp();
		$toTs = $to->setTimezone($utc)->getTimestamp();
		$seconds = ($toTs - $fromTs);
		if ($seconds <= 0) {
			return 0;
		}

		return (int)floor($seconds / 86400);
	}//end wholeDaysBetween()

	/**
	 * Parse an ISO-8601 timestamp into a UTC DateTimeImmutable, or null on failure.
	 *
	 * @param string $value ISO-8601 timestamp.
	 *
	 * @return DateTimeImmutable|null Parsed instant in UTC, or null when unparseable.
	 */
	private function parseUtc(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			$this->logger->warning('Shillinq: unparseable startTime for refund calc: ' . $value);
			return null;
		}

	}//end parseUtc()
}//end class
