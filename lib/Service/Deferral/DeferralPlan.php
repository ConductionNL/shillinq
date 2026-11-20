<?php

/**
 * Deferral Plan
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The release plan of a deferral schedule: one share per calendar-month
 * fiscal period of the service period, straight line, the last share taking
 * the rounding remainder so the shares add up to the total exactly
 * (ledger-period-end REQ-LPE-004, design D3). A whole month weighs one; a
 * month the service period covers in part weighs its covered days over the
 * month's days. So a licence for a calendar year releases twelve equal shares,
 * as the spec's scenario asks, and a period starting mid-month gives that
 * month its day-count share.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Deferral;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure release-plan arithmetic.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */
final class DeferralPlan {

	/**
	 * A multiple of every month length (28, 29, 30, 31), so a month's weight
	 * covered-days / month-days is an exact integer once scaled by it.
	 */
	private const MONTH_SCALE = 377580;

	/**
	 * The release plan for a total over a service period.
	 *
	 * Each share is floor(total × weight / total weight), a month's weight
	 * being its covered days over its days; the last share is what is left,
	 * so the shares add up to the total.
	 *
	 * @param int    $totalCents The amount spread, in cents (> 0).
	 * @param string $from       The first day of the service period (Y-m-d).
	 * @param string $to         The last day of the service period (Y-m-d), on or after $from.
	 *
	 * @return list<array{periodId: string, periodStart: string, periodEnd: string, days: int, amountCents: int, journalEntryId: null, releasedAt: null}>
	 *
	 * @throws InvalidArgumentException When the total or the dates are not usable.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	public static function releases(int $totalCents, string $from, string $to): array {
		if ($totalCents <= 0) {
			throw new InvalidArgumentException('The amount to spread must be more than zero');
		}

		$start = self::date(value: $from);
		$end   = self::date(value: $to);
		if ($end < $start) {
			throw new InvalidArgumentException('The service period ends before it starts');
		}

		$releases = [];
		$weights  = [];
		$cursor   = $start;
		while ($cursor <= $end) {
			$monthEnd = $cursor->modify('last day of this month');
			$shareEnd = min($monthEnd, $end);
			$days     = ((int)$cursor->diff($shareEnd)->days + 1);

			$weights[]  = intdiv($days * self::MONTH_SCALE, (int)$cursor->format('t'));
			$releases[] = [
				'periodId' => $cursor->format('Y-m'),
				'periodStart' => $cursor->format('Y-m-d'),
				'periodEnd' => $shareEnd->format('Y-m-d'),
				'days' => $days,
				'amountCents' => 0,
				'journalEntryId' => null,
				'releasedAt' => null,
			];
			$cursor = $monthEnd->modify('+1 day');
		}

		$totalWeight = array_sum($weights);
		$assigned    = 0;
		foreach ($weights as $index => $weight) {
			$releases[$index]['amountCents'] = intdiv($totalCents * $weight, $totalWeight);
			$assigned += $releases[$index]['amountCents'];
		}

		$last = (count($releases) - 1);
		$releases[$last]['amountCents'] += ($totalCents - $assigned);

		return $releases;
	}//end releases()

	/**
	 * Whether a service period spans more than one fiscal period.
	 *
	 * @param string $from The first day (Y-m-d).
	 * @param string $to   The last day (Y-m-d).
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When a date is not usable.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	public static function spansSeveralPeriods(string $from, string $to): bool {
		return self::date(value: $from)->format('Y-m') !== self::date(value: $to)->format('Y-m')
			&& self::date(value: $to) > self::date(value: $from);
	}//end spansSeveralPeriods()

	/**
	 * A strict Y-m-d date at midnight.
	 *
	 * @param string $value The date.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When it is not a real Y-m-d date.
	 */
	private static function date(string $value): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
		if ($date === false || $date->format('Y-m-d') !== trim($value)) {
			throw new InvalidArgumentException('Dates must be given as YYYY-MM-DD');
		}

		return $date;
	}//end date()
}//end class
