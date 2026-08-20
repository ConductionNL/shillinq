<?php

/**
 * Unit tests for IntercompanyMatchingCalculator.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntercompanyMatchingCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic intercompany matching arithmetic helper.
 *
 * Covers REQ-ICE-003 (per-side aggregation + match status), REQ-ICE-004 (tolerance
 * combination methods), REQ-ICE-006 (balanced elimination-line generation) and
 * REQ-ICE-009 (FX conversion). All monetary arithmetic is integer-cent based so
 * the tests assert exact cent values, never float-equality.
 *
 * PHPUnit assertions take positional ($actual, $expected) arguments; the custom
 * named-parameter sniff does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntercompanyMatchingCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var IntercompanyMatchingCalculator
	 */
	private IntercompanyMatchingCalculator $calc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new IntercompanyMatchingCalculator();

	}//end setUp()

	/**
	 * Cents round-trip is exact for representable amounts.
	 *
	 * @return void
	 */
	public function testToCentsAndFromCents(): void {
		self::assertSame(10000000, $this->calc->toCents(100000.0));
		self::assertSame(7, $this->calc->toCents(0.07));
		self::assertSame(100000.0, $this->calc->fromCents(10000000));
		self::assertSame(0.07, $this->calc->fromCents(7));

	}//end testToCentsAndFromCents()

	/**
	 * Conversion to cents rounds half-cents to the nearest cent (no IEEE-754 truncation).
	 *
	 * @return void
	 */
	public function testToCentsRoundsHalfCents(): void {
		// 0.1 + 0.2 famously != 0.3 in float; rounding to cents must still give 30.
		self::assertSame(30, $this->calc->toCents((0.1 + 0.2)));
		self::assertSame(2, $this->calc->toCents(0.015));

	}//end testToCentsRoundsHalfCents()

	/**
	 * Side aggregation nets debit minus credit across rows, in cents (REQ-ICE-003).
	 *
	 * @return void
	 */
	public function testSumSideCentsNetsDebitMinusCredit(): void {
		$rows = [
			['debitAmount' => 60000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
			['debitAmount' => 40000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
			['debitAmount' => 0.0,     'creditAmount' => 5000.0, 'currency' => 'EUR'],
		];
		// (60000 + 40000 - 5000) = 95000 -> 9500000 cents.
		self::assertSame(9500000, $this->calc->sumSideCents($rows));

	}//end testSumSideCentsNetsDebitMinusCredit()

	/**
	 * An empty side aggregates to zero cents.
	 *
	 * @return void
	 */
	public function testSumSideCentsEmptyIsZero(): void {
		self::assertSame(0, $this->calc->sumSideCents([]));

	}//end testSumSideCentsEmptyIsZero()

	/**
	 * FX conversion applies the supplied rate per non-EUR row (REQ-ICE-009).
	 *
	 * @return void
	 */
	public function testSumSideCentsAppliesFxRate(): void {
		$rows = [['debitAmount' => 108500.0, 'creditAmount' => 0.0, 'currency' => 'USD']];
		$rates = ['USD' => 0.921];
		// 108500 * 0.921 = 99928.5 -> rounds to 9992850 cents.
		self::assertSame(9992850, $this->calc->sumSideCents($rows, $rates));

	}//end testSumSideCentsAppliesFxRate()

	/**
	 * Missing rate for a currency defaults to 1.0 (no conversion).
	 *
	 * @return void
	 */
	public function testSumSideCentsDefaultsRateToOne(): void {
		$rows = [['debitAmount' => 100.0, 'creditAmount' => 0.0, 'currency' => 'GBP']];
		self::assertSame(10000, $this->calc->sumSideCents($rows, []));

	}//end testSumSideCentsDefaultsRateToOne()

	/**
	 * Mismatch in cents is the signed delta of the two sides.
	 *
	 * @return void
	 */
	public function testMismatchCents(): void {
		self::assertSame(0, $this->calc->mismatchCents(10000000, 10000000));
		self::assertSame(2500000, $this->calc->mismatchCents(10000000, 7500000));
		self::assertSame(-2500000, $this->calc->mismatchCents(7500000, 10000000));

	}//end testMismatchCents()

	/**
	 * Mismatch percentage is relative to the larger absolute side; zero/zero is 0.
	 *
	 * @return void
	 */
	public function testMismatchPercentage(): void {
		// Delta 700 cents on 10,000,000 cents larger side = 0.007%.
		self::assertSame(0.007, $this->calc->mismatchPercentage(10000000, 9999300));
		// The 25% case.
		self::assertSame(25.0, $this->calc->mismatchPercentage(10000000, 7500000));
		// Both sides zero -> 0 (no division by zero).
		self::assertSame(0.0, $this->calc->mismatchPercentage(0, 0));

	}//end testMismatchPercentage()

	/**
	 * The absolute-only tolerance method ignores the relative threshold.
	 *
	 * @return void
	 */
	public function testToleranceAbsoluteOnly(): void {
		// 7 cents within EUR 10 absolute.
		self::assertTrue($this->calc->isWithinTolerance(700, 99.0, 10.0, 0.5, 'absolute-only'));
		// 1500 cents (EUR 15) outside EUR 10 absolute even though relative tiny.
		self::assertFalse($this->calc->isWithinTolerance(1500, 0.001, 10.0, 0.5, 'absolute-only'));

	}//end testToleranceAbsoluteOnly()

	/**
	 * The max-of-absolute-relative method passes when either threshold passes (REQ-ICE-004).
	 *
	 * @return void
	 */
	public function testToleranceMaxOfAbsoluteRelative(): void {
		// Absolute fails (EUR 15 > 10) but relative passes (0.007% <= 0.5%) -> within.
		self::assertTrue($this->calc->isWithinTolerance(1500, 0.007, 10.0, 0.5, 'max-of-absolute-relative'));
		// Both fail -> outside.
		self::assertFalse($this->calc->isWithinTolerance(1500, 25.0, 10.0, 0.5, 'max-of-absolute-relative'));

	}//end testToleranceMaxOfAbsoluteRelative()

	/**
	 * The min-of-absolute-relative method requires both thresholds to pass (stricter).
	 *
	 * @return void
	 */
	public function testToleranceMinOfAbsoluteRelative(): void {
		// Both pass -> within.
		self::assertTrue($this->calc->isWithinTolerance(700, 0.007, 10.0, 0.5, 'min-of-absolute-relative'));
		// Absolute passes but relative fails -> outside (stricter).
		self::assertFalse($this->calc->isWithinTolerance(700, 25.0, 10.0, 0.5, 'min-of-absolute-relative'));

	}//end testToleranceMinOfAbsoluteRelative()

	/**
	 * Exact-boundary mismatch (== threshold) is considered within tolerance.
	 *
	 * @return void
	 */
	public function testToleranceBoundaryInclusive(): void {
		// Exactly EUR 10 absolute -> within (<=).
		self::assertTrue($this->calc->isWithinTolerance(1000, 99.0, 10.0, 0.5, 'absolute-only'));

	}//end testToleranceBoundaryInclusive()

	/**
	 * Perfect match: both sides equal and non-zero (REQ-ICE-003).
	 *
	 * @return void
	 */
	public function testMatchStatusPerfect(): void {
		self::assertSame('perfect-match', $this->calc->matchStatus(10000000, 10000000, true));

	}//end testMatchStatusPerfect()

	/**
	 * Within-tolerance vs outside-tolerance keyed off the supplied flag.
	 *
	 * @return void
	 */
	public function testMatchStatusToleranceFlag(): void {
		self::assertSame('within-tolerance', $this->calc->matchStatus(10000000, 9999300, true));
		self::assertSame('outside-tolerance', $this->calc->matchStatus(10000000, 7500000, false));

	}//end testMatchStatusToleranceFlag()

	/**
	 * One-sided detection: exactly one side booked (REQ-ICE-003).
	 *
	 * @return void
	 */
	public function testMatchStatusOneSided(): void {
		self::assertSame('one-sided-A', $this->calc->matchStatus(10000000, 0, false));
		self::assertSame('one-sided-B', $this->calc->matchStatus(0, 10000000, false));

	}//end testMatchStatusOneSided()

	/**
	 * Both sides zero is a (degenerate) perfect match, not one-sided.
	 *
	 * @return void
	 */
	public function testMatchStatusBothZero(): void {
		self::assertSame('perfect-match', $this->calc->matchStatus(0, 0, true));

	}//end testMatchStatusBothZero()

	/**
	 * FX convert rounds to 2 decimals (REQ-ICE-009).
	 *
	 * @return void
	 */
	public function testConvert(): void {
		self::assertSame(99928.5, $this->calc->convert(108500.0, 0.921));
		self::assertSame(25067.5, $this->calc->convert(27100.0, 0.925));

	}//end testConvert()

	/**
	 * Elimination lines always balance; debit on A, credit on B (REQ-ICE-006).
	 *
	 * @return void
	 */
	public function testBuildEliminationLinesBalanced(): void {
		$built = $this->calc->buildEliminationLines(
			10000000,
			10000000,
			'8200',
			'4400',
			'IC elimination test'
		);

		self::assertSame(100000.0, $built['totalDebit']);
		self::assertSame(100000.0, $built['totalCredit']);
		self::assertSame($built['totalDebit'], $built['totalCredit']);
		self::assertCount(2, $built['lines']);
		self::assertSame('8200', $built['lines'][0]['glAccount']);
		self::assertSame(100000.0, $built['lines'][0]['debitAmount']);
		self::assertSame(0.0, $built['lines'][0]['creditAmount']);
		self::assertSame('4400', $built['lines'][1]['glAccount']);
		self::assertSame(0.0, $built['lines'][1]['debitAmount']);
		self::assertSame(100000.0, $built['lines'][1]['creditAmount']);

	}//end testBuildEliminationLinesBalanced()

	/**
	 * Elimination uses the smaller side so it never exceeds the booked amount.
	 *
	 * @return void
	 */
	public function testBuildEliminationLinesUsesCommonAmount(): void {
		// A booked 100k, B only 75k -> eliminate the common 75k, still balanced.
		$built = $this->calc->buildEliminationLines(10000000, 7500000, '8200', '4400', 'partial');
		self::assertSame(75000.0, $built['totalDebit']);
		self::assertSame(75000.0, $built['totalCredit']);

	}//end testBuildEliminationLinesUsesCommonAmount()

	/**
	 * Default cause classification: currency difference -> fx-translation, else unknown.
	 *
	 * @return void
	 */
	public function testDefaultCauseClassification(): void {
		self::assertSame('fx-translation', $this->calc->defaultCauseClassification(true));
		self::assertSame('unknown', $this->calc->defaultCauseClassification(false));

	}//end testDefaultCauseClassification()
}//end class
