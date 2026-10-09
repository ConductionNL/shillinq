<?php

/**
 * Payment Plan Schedule
 *
 * Draws up the instalments of a payment plan so they add up to the plan total
 * to the cent. By count: the total divided by the count, rounded to cents, the
 * last instalment taking the difference. By amount: as many instalments of that
 * amount as the total needs, the last one the remainder (design.md D3).
 *
 * @category PaymentPlan
 * @package  OCA\Shillinq\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentPlan;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure schedule arithmetic, in cents.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanSchedule {
	/**
	 * The most instalments a plan may have.
	 *
	 * @var integer
	 */
	public const MAX_INSTALMENTS = 120;

	/**
	 * Draw up the schedule.
	 *
	 * @param float      $total        The plan total.
	 * @param string     $frequency    `weekly` or `monthly`.
	 * @param string     $firstDueDate The first due date, Y-m-d.
	 * @param int|null   $count        The number of instalments, or null when agreed by amount.
	 * @param float|null $amount       The amount per instalment, or null when agreed by count.
	 *
	 * @return array<int,array{instalmentNumber:int,dueDate:string,amount:float}>
	 *
	 * @throws InvalidArgumentException When the terms cannot make a schedule.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function build(float $total, string $frequency, string $firstDueDate, ?int $count, ?float $amount): array {
		$totalCents = (int)round($total * 100);
		if ($totalCents <= 0) {
			throw new InvalidArgumentException('The plan total must be above zero.');
		}

		$first = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDueDate);
		if ($first === false || in_array($frequency, ['weekly', 'monthly'], true) === false) {
			throw new InvalidArgumentException('Choose a first due date and a weekly or monthly frequency.');
		}

		$amounts = $this->amounts(totalCents: $totalCents, count: $count, amount: $amount);
		$schedule = [];
		foreach ($amounts as $index => $cents) {
			$schedule[] = [
				'instalmentNumber' => ($index + 1),
				'dueDate' => $this->dueDate(first: $first, frequency: $frequency, index: $index),
				'amount' => ($cents / 100.0),
			];
		}

		return $schedule;

	}//end build()

	/**
	 * Whether a set of instalment amounts adds up to the total to the cent.
	 *
	 * @param float             $total   The plan total.
	 * @param array<int,mixed>  $amounts The instalment amounts.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-1.1
	 */
	public function balances(float $total, array $amounts): bool {
		if ($amounts === []) {
			return false;
		}

		$sum = 0;
		foreach ($amounts as $amount) {
			$sum += (int)round((float)$amount * 100);
		}

		return $sum === (int)round($total * 100);

	}//end balances()

	/**
	 * The instalment amounts in cents.
	 *
	 * @param int        $totalCents The total in cents.
	 * @param int|null   $count      The number of instalments.
	 * @param float|null $amount     The amount per instalment.
	 *
	 * @return array<int,int>
	 *
	 * @throws InvalidArgumentException When neither or a useless term is given.
	 */
	private function amounts(int $totalCents, ?int $count, ?float $amount): array {
		if ($count !== null && $count > 0) {
			$this->assertCount(count: $count);
			$base = intdiv($totalCents, $count);
			$amounts = array_fill(0, $count, $base);
			$amounts[($count - 1)] = ($totalCents - ($base * ($count - 1)));
			return $amounts;
		}

		$amountCents = (int)round((float)$amount * 100);
		if ($amountCents <= 0) {
			throw new InvalidArgumentException('Choose a number of instalments or an instalment amount.');
		}

		$count = (int)ceil($totalCents / $amountCents);
		$this->assertCount(count: $count);
		$amounts = array_fill(0, $count, $amountCents);
		$amounts[($count - 1)] = ($totalCents - ($amountCents * ($count - 1)));
		return $amounts;

	}//end amounts()

	/**
	 * Refuse a schedule longer than the maximum.
	 *
	 * @param int $count The number of instalments.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the count is above the maximum.
	 */
	private function assertCount(int $count): void {
		if ($count > self::MAX_INSTALMENTS) {
			throw new InvalidArgumentException(sprintf('A plan can have at most %d instalments.', self::MAX_INSTALMENTS));
		}

	}//end assertCount()

	/**
	 * The due date of instalment $index (from 0).
	 *
	 * Monthly dates keep the first date's day, or the month's last day when the
	 * month is shorter (31 January, 28 February, 31 March).
	 *
	 * @param DateTimeImmutable $first     The first due date.
	 * @param string            $frequency `weekly` or `monthly`.
	 * @param int               $index     The instalment index.
	 *
	 * @return string Y-m-d.
	 */
	private function dueDate(DateTimeImmutable $first, string $frequency, int $index): string {
		if ($frequency === 'weekly') {
			return $first->modify('+' . (7 * $index) . ' days')->format('Y-m-d');
		}

		$month = $first->modify('first day of this month')->modify('+' . $index . ' months');
		$day = min((int)$first->format('j'), (int)$month->format('t'));
		return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $day)->format('Y-m-d');

	}//end dueDate()
}//end class
