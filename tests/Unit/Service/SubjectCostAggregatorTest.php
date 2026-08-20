<?php

/**
 * SubjectCostAggregator Unit Tests
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SubjectCostAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The aggregator must never publish a total it could not fully compute.
 *
 * That is the property most of these tests exist for. A cost that silently
 * omits one person's hours is worse than no cost: it is plausible, it is
 * always LOWER than the truth, and nothing about it looks wrong on a case
 * detail page. So a partial rate set must yield `complete: false` and a null
 * cost, not a smaller number.
 *
 * @covers \OCA\Shillinq\Service\SubjectCostAggregator
 */
class SubjectCostAggregatorTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var SubjectCostAggregator
	 */
	private SubjectCostAggregator $aggregator;

	/**
	 * Build the aggregator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->aggregator = new SubjectCostAggregator(new NullLogger());
	}

	/**
	 * Hours are summed per person and costed at that person's rate.
	 *
	 * @return void
	 */
	public function testCostsEachPersonAtTheirOwnRate(): void {
		$result = $this->aggregator->aggregate(
			[
				['personId' => 'alice', 'hours' => 2.0],
				['personId' => 'alice', 'hours' => 1.5],
				['personId' => 'bob', 'hours' => 4.0],
			],
			['alice' => 6000, 'bob' => 5000]
		);

		self::assertSame(7.5, $result['hours']);
		self::assertTrue($result['complete']);
		// alice 3.5h @ 60.00 = 210.00, bob 4h @ 50.00 = 200.00
		self::assertSame((21000 + 20000), $result['costCents']);
		self::assertSame('EUR', $result['currency']);
	}

	/**
	 * One unpriced person withholds the WHOLE total, not just their share.
	 *
	 * This is the assertion that matters. Returning bob's cost alone would be
	 * a plausible, too-low number on a case page with no indication that
	 * anything is missing.
	 *
	 * @return void
	 */
	public function testAnyUnpricedPersonWithholdsTheEntireCost(): void {
		$result = $this->aggregator->aggregate(
			[
				['personId' => 'alice', 'hours' => 3.0],
				['personId' => 'bob', 'hours' => 4.0],
			],
			['bob' => 5000]
		);

		self::assertNull($result['costCents'], 'a partial cost must never be published');
		self::assertFalse($result['complete']);
		self::assertSame(['alice'], $result['unpricedPersonIds']);
		self::assertSame(7.0, $result['hours'], 'hours stay complete even when the cost cannot');
	}

	/**
	 * Hours are reported even when no rate is available at all.
	 *
	 * Hours are effort, not currency — ADR-081 lets a domain app show them.
	 *
	 * @return void
	 */
	public function testHoursSurviveWithNoRatesAtAll(): void {
		$result = $this->aggregator->aggregate(
			[['personId' => 'alice', 'hours' => 2.25]],
			[]
		);

		self::assertSame(2.25, $result['hours']);
		self::assertNull($result['costCents']);
		self::assertFalse($result['complete']);
	}

	/**
	 * An empty hour set is not a zero cost.
	 *
	 * Zero hours costing zero is arguably true, but `complete: true` with a 0
	 * total would render "€0.00" on a case nobody has booked time to — which
	 * reads as "this was free" rather than "nothing recorded yet".
	 *
	 * @return void
	 */
	public function testNoHoursIsNotAZeroCost(): void {
		$result = $this->aggregator->aggregate([], ['alice' => 6000]);

		self::assertSame(0.0, $result['hours']);
		self::assertFalse($result['complete']);
		self::assertNull($result['costCents']);
		self::assertSame([], $result['perPerson']);
	}

	/**
	 * Hours with no person are counted but never priced.
	 *
	 * @return void
	 */
	public function testUnattributedHoursCountButCannotBePriced(): void {
		$result = $this->aggregator->aggregate(
			[
				['personId' => '', 'hours' => 1.0],
				['personId' => 'alice', 'hours' => 2.0],
			],
			['alice' => 6000]
		);

		self::assertSame(3.0, $result['hours'], 'unowned hours still happened');
		self::assertFalse($result['complete']);
		self::assertContains('(unattributed)', $result['unpricedPersonIds']);
	}

	/**
	 * Non-numeric hours are rejected rather than cast to zero.
	 *
	 * PHP would turn '' and 'n/a' into 0.0 without complaint, which quietly
	 * drops a row into the breakdown as a person who worked no hours.
	 *
	 * @return void
	 */
	public function testNonNumericHoursAreRejectedNotCastToZero(): void {
		// The junk rows belong to DIFFERENT people on purpose. An earlier
		// version of this test put all three rows on alice, and a mutation
		// replacing the numeric check with a blanket `(float) $value` PASSED
		// it: 'n/a' and '' both cast to 0.0, alice's total was still 2.5, and
		// the breakdown still had exactly one entry. Nothing observable moved.
		//
		// With the junk on carol and dave, a 0.0 cast invents two people who
		// "worked no hours", neither of whom has a rate — so the breakdown
		// grows and `complete` flips to false. That is the difference the
		// assertion is supposed to detect.
		$result = $this->aggregator->aggregate(
			[
				['personId' => 'carol', 'hours' => 'n/a'],
				['personId' => 'dave', 'hours' => ''],
				['personId' => 'alice', 'hours' => '2.5'],
			],
			['alice' => 4000]
		);

		self::assertSame(2.5, $result['hours'], 'only the numeric row counts');
		self::assertCount(1, $result['perPerson'], 'a rejected row must not invent a person');
		self::assertSame(['alice'], array_column($result['perPerson'], 'personId'));
		self::assertTrue($result['complete'], 'the one real row is fully priced');
		self::assertSame(10000, $result['costCents']);
	}

	/**
	 * Rounding happens once per person, not once at the end.
	 *
	 * Three rows of 1/3 hour at 1000 cents is 1000 cents exactly when summed
	 * per person. Rounding each ROW instead would give 333+333+333 = 999.
	 *
	 * @return void
	 */
	public function testRoundingIsPerPersonNotPerRow(): void {
		$third = (1 / 3);
		$result = $this->aggregator->aggregate(
			[
				['personId' => 'alice', 'hours' => $third],
				['personId' => 'alice', 'hours' => $third],
				['personId' => 'alice', 'hours' => $third],
			],
			['alice' => 1000]
		);

		self::assertSame(1000, $result['costCents']);
	}

	/**
	 * A negative or non-integer rate is treated as no rate.
	 *
	 * @return void
	 */
	public function testAMalformedRateIsTreatedAsAbsent(): void {
		$result = $this->aggregator->aggregate(
			[['personId' => 'alice', 'hours' => 1.0]],
			['alice' => -500]
		);

		self::assertFalse($result['complete']);
		self::assertNull($result['costCents']);
		self::assertSame(['alice'], $result['unpricedPersonIds']);
	}
}
