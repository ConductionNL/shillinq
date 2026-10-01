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
		if (in_array($method, self::METHODS, true) === false) {
			throw new DomainException(sprintf('Depreciation method %s cannot be planned.', $method));
		}

		if ($months < 1) {
			throw new DomainException('An asset needs at least one month of useful life left.');
		}

		if ($method === 'degressive' && $rate <= 0.0) {
			throw new DomainException('Degressive depreciation needs a rate above zero.');
		}

		$first = DateTimeImmutable::createFromFormat('!Y-m', $fromMonth);
		if ($first === false) {
			throw new DomainException(sprintf('%s is not a month.', $fromMonth));
		}

		$depreciable = max(0, ($bookCents - $residualCents));
		$lines = [];
		$book = $bookCents;
		for ($index = 0; $index < $months; $index++) {
			$cents = $this->monthCents(method: $method, depreciable: $depreciable, book: $book, residual: $residualCents, months: $months, index: $index, rate: $rate);
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

	}//end plan()

	/**
	 * One month's amount.
	 *
	 * @param string $method      The method.
	 * @param int    $depreciable The depreciable amount at the start of the plan.
	 * @param int    $book        The book value at the start of this month.
	 * @param int    $residual    The residual value.
	 * @param int    $months      The months in the plan.
	 * @param int    $index       This month's index, from zero.
	 * @param float  $rate        The annual degressive rate.
	 *
	 * @return int The amount in cents.
	 */
	private function monthCents(string $method, int $depreciable, int $book, int $residual, int $months, int $index, float $rate): int {
		$room = max(0, ($book - $residual));
		if ($method === 'degressive') {
			if ($index === ($months - 1)) {
				return $room;
			}

			return min($room, (int)round($book * $rate / 12));
		}

		// Straight line and units of production planned evenly over the
		// months left give the same monthly share; the last month takes the
		// rounding so the plan ends exactly on the residual value.
		if ($index === ($months - 1)) {
			return $room;
		}

		return min($room, intdiv($depreciable, $months));

	}//end monthCents()
}//end class
