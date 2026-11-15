<?php

/**
 * Unit tests for RecurringModifierExpander: the rows it asks the shared schedule
 * expander about for each modifier type, and the monthly deltas it derives.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;
use OCA\Shillinq\Service\RecurringModifierExpander;
use PHPUnit\Framework\TestCase;

/**
 * A schedule expander that charges standardAmount for every month from validFrom
 * to validTo within the year, recording each row it is asked about.
 */
final class MonthCountingScheduleExpander implements KnownCostScheduleExpanderInterface {

	/**
	 * The rows asked about, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Rows answered as unknowable, by their validFrom.
	 *
	 * @var list<string>
	 */
	public array $unknowableFrom = [];

	/**
	 * Expand one row.
	 *
	 * @param array<string, mixed>      $recurring  The row.
	 * @param int                       $fiscalYear The year.
	 * @param array<string, mixed>|null $contract   Ignored.
	 *
	 * @return array<string, mixed>
	 */
	public function expand(array $recurring, int $fiscalYear, ?array $contract): array {
		$this->rows[] = $recurring;
		if (in_array((string)($recurring['validFrom'] ?? ''), $this->unknowableFrom, true) === true) {
			return ['kind' => 'unknown'];
		}

		$from    = (string)($recurring['validFrom'] ?? ($fiscalYear . '-01-01'));
		$to      = (string)($recurring['validTo'] ?? ($fiscalYear . '-12-31'));
		$monthly = [];
		foreach (range(1, 12) as $month) {
			$key            = sprintf('%02d', $month);
			$first          = sprintf('%d-%s-01', $fiscalYear, $key);
			$monthly[$key]  = ($first >= substr($from, 0, 8) . '01' && $first <= $to) ? (int)$recurring['standardAmount'] : 0;
		}

		return ['kind' => 'amounts', 'monthlyCents' => $monthly];
	}
}//end class

/**
 * @covers \OCA\Shillinq\Service\RecurringModifierExpander
 */
class RecurringModifierExpanderTest extends TestCase {

	/**
	 * The real recurring row: 1000 cents a month all year.
	 *
	 * @var array<string, mixed>
	 */
	private const REAL = ['id' => 'rc-1', 'validFrom' => '2026-01-01', 'validTo' => null, 'standardAmount' => 1000];

	/**
	 * Twelve months of the same value.
	 *
	 * @param list<int> $values Twelve values.
	 *
	 * @return array<string, int>
	 */
	private static function months(array $values): array {
		return array_combine(['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $values);
	}//end months()

	/**
	 * Ending the row on 30 April removes the months after it; the real row is expanded first.
	 *
	 * @return void
	 */
	public function testARecurringEndRemovesTheLaterMonths(): void {
		$expander = new MonthCountingScheduleExpander();

		$deltas = (new RecurringModifierExpander($expander))->monthlyDeltas(
			real: self::REAL,
			modifier: ['effectiveDate' => '2026-04-30'],
			type: 'RECURRING_END',
			fiscalYear: 2026
		);

		$this->assertSame(self::months([0, 0, 0, 0, -1000, -1000, -1000, -1000, -1000, -1000, -1000, -1000]), $deltas);
		$this->assertSame([self::REAL, array_merge(self::REAL, ['validTo' => '2026-04-30'])], $expander->rows);
	}//end testARecurringEndRemovesTheLaterMonths()

	/**
	 * A row already ending before the cap keeps its own end, so nothing changes.
	 *
	 * @return void
	 */
	public function testAnEarlierRealEndIsKept(): void {
		$expander = new MonthCountingScheduleExpander();
		$real     = array_merge(self::REAL, ['validTo' => '2026-03-31']);

		$deltas = (new RecurringModifierExpander($expander))->monthlyDeltas(
			real: $real,
			modifier: ['effectiveDate' => '2026-09-30'],
			type: 'RECURRING_END',
			fiscalYear: 2026
		);

		$this->assertSame(self::months(array_fill(0, 12, 0)), $deltas);
		$this->assertSame('2026-03-31', $expander->rows[1]['validTo']);
	}//end testAnEarlierRealEndIsKept()

	/**
	 * An amount change splits the row the day before the effective date and charges the new amount after.
	 *
	 * @return void
	 */
	public function testAnAmountChangeSplitsTheRow(): void {
		$expander = new MonthCountingScheduleExpander();

		$deltas = (new RecurringModifierExpander($expander))->monthlyDeltas(
			real: self::REAL,
			modifier: ['effectiveDate' => '2026-07-01', 'newStandardAmount' => 1500],
			type: 'RECURRING_AMOUNT_CHANGE',
			fiscalYear: 2026
		);

		$this->assertSame(self::months([0, 0, 0, 0, 0, 0, 500, 500, 500, 500, 500, 500]), $deltas);
		$this->assertSame(
			[
				self::REAL,
				array_merge(self::REAL, ['validTo' => '2026-06-30']),
				array_merge(self::REAL, ['validFrom' => '2026-07-01', 'standardAmount' => 1500]),
			],
			$expander->rows
		);
	}//end testAnAmountChangeSplitsTheRow()

	/**
	 * The day before the first of March is the last of February, leap years included.
	 *
	 * @return void
	 */
	public function testTheDayBeforeCrossesMonths(): void {
		$expander = new MonthCountingScheduleExpander();

		(new RecurringModifierExpander($expander))->monthlyDeltas(
			real: self::REAL,
			modifier: ['effectiveDate' => '2028-03-01', 'newStandardAmount' => 1000],
			type: 'RECURRING_AMOUNT_CHANGE',
			fiscalYear: 2028
		);

		$this->assertSame('2028-02-29', $expander->rows[1]['validTo']);
	}//end testTheDayBeforeCrossesMonths()

	/**
	 * An unknowable slice makes the whole result unknowable, as does an unknowable real row.
	 *
	 * @return void
	 */
	public function testAnUnknowableSliceIsUnknowable(): void {
		$expander                 = new MonthCountingScheduleExpander();
		$expander->unknowableFrom = ['2026-07-01'];

		$this->assertNull(
			(new RecurringModifierExpander($expander))->monthlyDeltas(
				real: self::REAL,
				modifier: ['effectiveDate' => '2026-07-01', 'newStandardAmount' => 1500],
				type: 'RECURRING_AMOUNT_CHANGE',
				fiscalYear: 2026
			)
		);

		$expander                 = new MonthCountingScheduleExpander();
		$expander->unknowableFrom = ['2026-01-01'];
		$this->assertNull(
			(new RecurringModifierExpander($expander))->monthlyDeltas(
				real: self::REAL,
				modifier: ['effectiveDate' => '2026-04-30'],
				type: 'RECURRING_END',
				fiscalYear: 2026
			)
		);
	}//end testAnUnknowableSliceIsUnknowable()

	/**
	 * Another modifier type has no deltas, after reading only the real row.
	 *
	 * @return void
	 */
	public function testAnotherTypeHasNoDeltas(): void {
		$expander = new MonthCountingScheduleExpander();

		$this->assertNull(
			(new RecurringModifierExpander($expander))->monthlyDeltas(real: self::REAL, modifier: [], type: 'ONE_OFF', fiscalYear: 2026)
		);
		$this->assertSame([self::REAL], $expander->rows);
	}//end testAnotherTypeHasNoDeltas()
}//end class
