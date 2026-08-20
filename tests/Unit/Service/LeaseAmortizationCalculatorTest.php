<?php

/**
 * Unit tests for LeaseAmortizationCalculator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic IFRS 16 amortization engine against the worked example in
 * REQ-LA-001 / REQ-LA-002 (36-month lease, 1,000/month, 4% IBR) and the
 * restoration-obligation discounting in REQ-LA-005.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseAmortizationCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var LeaseAmortizationCalculator
	 */
	private LeaseAmortizationCalculator $calc;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new LeaseAmortizationCalculator();

	}//end setUp()

	/**
	 * The worked-example lease from REQ-LA-001.
	 *
	 * @return array<string,mixed>
	 */
	private function workedExample(): array {
		return [
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'initialDirectCosts' => 500.0,
			'leaseIncentivesReceived' => 0.0,
			'classification' => 'IFRS16-capitalised',
			'extensionOptions' => [],
		];
	}//end workedExample()

	/**
	 * Periodic rate prorates the annual IBR by the payment frequency (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testPeriodicRate(): void {
		self::assertEqualsWithDelta(0.0033333, $this->calc->periodicRate(4.0, 'monthly'), 1e-6);
		self::assertEqualsWithDelta(0.01, $this->calc->periodicRate(4.0, 'quarterly'), 1e-9);
		self::assertEqualsWithDelta(0.04, $this->calc->periodicRate(4.0, 'annual'), 1e-9);

	}//end testPeriodicRate()

	/**
	 * Present value of the 36-month ordinary annuity at 4% is ~33,800 (REQ-LA-001).
	 *
	 * @return void
	 */
	public function testPresentValueMatchesWorkedExample(): void {
		$rate = $this->calc->periodicRate(4.0, 'monthly');
		$pv = $this->calc->presentValue(1000.0, 36, $rate, 'in-arrears');
		// Standard PV of 36 monthly 1,000 payments at 4% annual ≈ 33,870.77.
		self::assertEqualsWithDelta(33870.77, $pv, 1.0);

	}//end testPresentValueMatchesWorkedExample()

	/**
	 * Annuity-due (in-advance) PV exceeds the ordinary annuity by one period of interest.
	 *
	 * @return void
	 */
	public function testPresentValueInAdvanceIsLarger(): void {
		$rate = $this->calc->periodicRate(4.0, 'monthly');
		$arrears = $this->calc->presentValue(1000.0, 36, $rate, 'in-arrears');
		$advance = $this->calc->presentValue(1000.0, 36, $rate, 'in-advance');
		self::assertGreaterThan($arrears, $advance);
		self::assertEqualsWithDelta($arrears * (1 + $rate), $advance, 0.01);

	}//end testPresentValueInAdvanceIsLarger()

	/**
	 * A zero rate degenerates to a simple sum (no discounting).
	 *
	 * @return void
	 */
	public function testPresentValueZeroRate(): void {
		self::assertSame(36000.0, $this->calc->presentValue(1000.0, 36, 0.0, 'in-arrears'));

	}//end testPresentValueZeroRate()

	/**
	 * Opening RoU asset = PV + initial-direct-costs (REQ-LA-001).
	 *
	 * @return void
	 */
	public function testOpeningBalances(): void {
		$opening = $this->calc->openingBalances($this->workedExample());
		self::assertEqualsWithDelta(33870.77, $opening['liability'], 1.0);
		// RoU = liability + 500 initial direct costs.
		self::assertEqualsWithDelta(($opening['liability'] + 500.0), $opening['rouAsset'], 0.01);
		self::assertSame(36, $opening['periods']);

	}//end testOpeningBalances()

	/**
	 * Restoration obligation is discounted into the opening RoU asset (REQ-LA-005).
	 *
	 * @return void
	 */
	public function testRestorationObligationDiscounted(): void {
		$lease = [
			'nonCancellableTermMonths' => 60,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 0.0,
			'ibrPercent' => 0.0,
			'restorationObligation' => ['estimatedCost' => 75000.0, 'discountRate' => 0.045],
			'classification' => 'IFRS16-capitalised',
		];
		$opening = $this->calc->openingBalances($lease);
		// 75,000 / 1.045^5 ≈ 60,184.
		self::assertEqualsWithDelta(60184.0, $opening['restorationPv'], 5.0);
		self::assertEqualsWithDelta($opening['restorationPv'], $opening['rouAsset'], 0.01);

	}//end testRestorationObligationDiscounted()

	/**
	 * Schedule length includes reasonably-certain extension months only (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testScheduleLengthHonoursReasonablyCertainExtensions(): void {
		$base = $this->workedExample();
		self::assertSame(36, $this->calc->scheduleLength($base));

		$base['extensionOptions'] = [
			['months' => 24, 'exerciseLikelihood' => 'reasonably-certain'],
			['months' => 12, 'exerciseLikelihood' => 'possible'],
		];
		// Only the 24 reasonably-certain months extend the schedule.
		self::assertSame(60, $this->calc->scheduleLength($base));

	}//end testScheduleLengthHonoursReasonablyCertainExtensions()

	/**
	 * The full schedule amortises the liability to exactly zero (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testScheduleAmortisesToZero(): void {
		$rows = $this->calc->buildSchedule($this->workedExample());
		self::assertCount(36, $rows);

		// Period 1 interest = opening × monthly rate.
		self::assertEqualsWithDelta(112.90, $rows[0]['interestAccrued'], 0.5);
		self::assertSame(1000.0, $rows[0]['paymentAppliedTotal']);

		// The final period extinguishes the liability and the RoU asset exactly.
		$last = $rows[35];
		self::assertSame(0.0, $last['closingLeaseLiability']);
		self::assertSame(0.0, $last['closingRouAsset']);

	}//end testScheduleAmortisesToZero()

	/**
	 * Across the schedule the principal portions sum to the opening liability.
	 *
	 * @return void
	 */
	public function testPrincipalPortionsSumToOpeningLiability(): void {
		$rows = $this->calc->buildSchedule($this->workedExample());
		$opening = $this->calc->openingBalances($this->workedExample());

		$principalCents = 0;
		foreach ($rows as $row) {
			$principalCents += (int)round(((float)$row['paymentPrincipalPortion']) * 100);
		}

		self::assertSame(
			(int)round($opening['liability'] * 100),
			$principalCents,
			'Sum of principal portions must equal the opening liability'
		);

	}//end testPrincipalPortionsSumToOpeningLiability()

	/**
	 * Depreciation is straight-line: the RoU asset reduces by an equal charge each period.
	 *
	 * @return void
	 */
	public function testStraightLineDepreciation(): void {
		$rows = $this->calc->buildSchedule($this->workedExample());
		$first = (float)$rows[0]['depreciationCharge'];
		// RoU 34,370.77 / 36 ≈ 954.74 per period.
		self::assertEqualsWithDelta(954.74, $first, 1.0);

		// Every non-final period charges the same amount.
		for ($i = 1; $i < 35; $i++) {
			self::assertSame($first, (float)$rows[$i]['depreciationCharge']);
		}

	}//end testStraightLineDepreciation()

	/**
	 * A lease with no term yields an empty schedule (defensive).
	 *
	 * @return void
	 */
	public function testEmptyScheduleForZeroTerm(): void {
		self::assertSame([], $this->calc->buildSchedule(['nonCancellableTermMonths' => 0]));

	}//end testEmptyScheduleForZeroTerm()
}//end class
