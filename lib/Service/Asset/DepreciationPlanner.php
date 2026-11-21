<?php

/**
 * Depreciation Planner
 *
 * The monthly depreciation amounts of an asset from a month onward, given its
 * book value then and the months it has left (assets-method-change-and-reserve,
 * REQ-AMCR-002). Straight line spreads the depreciable amount evenly and puts
 * the rounding in the last month; degressive takes the annual rate over twelve
 * of the book value at the start of each month; units of production spreads
 * the depreciable amount over the remaining units, planned evenly over the
 * remaining months. No method goes below the residual value. Amounts are cents.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

use DateTimeImmutable;
use DomainException;

/**
 * Pure arithmetic: no records are read or written here.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class DepreciationPlanner {

	/**
	 * The methods this planner knows, as FixedAsset names them.
	 */
	public const METHODS = ['linear', 'degressive', 'units-of-production'];

	/**
	 * The monthly lines from a month onward.
	 *
	 * @param string $fromMonth     The first month, YYYY-MM.
	 * @param int    $bookCents     The book value at the start of that month.
	 * @param int    $residualCents The residual value the plan stops at.
	 * @param int    $months        The months left.
	 * @param string $method        linear, degressive or units-of-production.
	 * @param float  $rate          The annual degressive rate as a fraction (0.2 is 20 percent).
	 *
	 * @return list<array{month: string, start: string, end: string, cents: int, bookCents: int}> One line per month.
	 *
	 * @throws DomainException When the method is unknown or the inputs cannot make a plan.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function plan(string $fromMonth, int $bookCents, int $residualCents, int $months, string $method, float $rate = 0.0): array {
		$run = new DepreciationPlanRun(
			fromMonth: $fromMonth,
			bookCents: $bookCents,
			residualCents: $residualCents,
			months: $months,
			method: $method,
			rate: $rate
		);
		$this->assertPlannable(run: $run);
		$first = DateTimeImmutable::createFromFormat('!Y-m', $fromMonth);
		if ($first === false) {
			throw new DomainException(sprintf('%s is not a month.', $fromMonth));
		}

		return $this->planLines(run: $run, first: $first);

	}//end plan()

	/**
	 * Refuse an unknown method, no months left, or a degressive plan without a rate.
	 *
	 * @param DepreciationPlanRun $run The plan's inputs.
	 *
	 * @return void
	 *
	 * @throws DomainException When the inputs cannot make a plan.
	 */
	private function assertPlannable(DepreciationPlanRun $run): void {
		if (in_array($run->method, self::METHODS, true) === false) {
			throw new DomainException(sprintf('Depreciation method %s cannot be planned.', $run->method));
		}

		if ($run->months < 1) {
			throw new DomainException('An asset needs at least one month of useful life left.');
		}

		if ($run->method === 'degressive' && $run->rate <= 0.0) {
			throw new DomainException('Degressive depreciation needs a rate above zero.');
		}

	}//end assertPlannable()

	/**
	 * One line per month from the first month, with the book value after it.
	 *
	 * @param DepreciationPlanRun $run   The plan's inputs.
	 * @param DateTimeImmutable   $first The first month.
	 *
	 * @return list<array{month: string, start: string, end: string, cents: int, bookCents: int}>
	 */
	private function planLines(DepreciationPlanRun $run, DateTimeImmutable $first): array {
		$lines = [];
		$book = $run->bookCents;
		foreach ($this->monthAmounts(run: $run) as $index => $cents) {
			$book -= $cents;
			$month = $first->modify(sprintf('+%d month', $index));
			$lines[] = [
				'month'     => $month->format('Y-m'),
				'start'     => $month->format('Y-m-01'),
				'end'       => $month->format('Y-m-t'),
				'cents'     => $cents,
				'bookCents' => $book,
			];
		}

		return $lines;

	}//end planLines()

	/**
	 * Each month's amount in cents. The last month takes what is left above
	 * the residual value, so the plan ends exactly on it.
	 *
	 * @param DepreciationPlanRun $run The plan's inputs.
	 *
	 * @return list<int>
	 */
	private function monthAmounts(DepreciationPlanRun $run): array {
		$amounts = [];
		$book = $run->bookCents;
		for ($index = 0; $index < ($run->months - 1); $index++) {
			$cents = $this->monthCents(run: $run, book: $book);
			$book -= $cents;
			$amounts[] = $cents;
		}

		$amounts[] = max(0, ($book - $run->residualCents));
		return $amounts;

	}//end monthAmounts()

	/**
	 * One month's amount before the last month, capped at what is left above
	 * the residual value.
	 *
	 * Degressive takes the annual rate on the current book value; straight
	 * line and units of production planned evenly over the months left give
	 * the same monthly share.
	 *
	 * @param DepreciationPlanRun $run  The plan's inputs.
	 * @param int                 $book The book value at the start of this month.
	 *
	 * @return int The amount in cents.
	 */
	private function monthCents(DepreciationPlanRun $run, int $book): int {
		$room = max(0, ($book - $run->residualCents));
		if ($run->method === 'degressive') {
			return min($room, (int)round($book * $run->rate / 12));
		}

		return min($room, intdiv($run->depreciableCents(), $run->months));

	}//end monthCents()
}//end class
