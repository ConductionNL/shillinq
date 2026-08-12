<?php

/**
 * Unit tests for BcfCompensationCalculator.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BcfCompensationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic BCF compensable-VAT arithmetic helper.
 *
 * Covers REQ-BCF-002 (filter compensable accounts, weight by percentage, sum),
 * REQ-BCF-004 (percentage clamping) and REQ-BCF-003 (submit precondition).
 *
 * PHPUnit assertions take positional ($actual, $expected) arguments; the custom
 * named-parameter sniff does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfCompensationCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var BcfCompensationCalculator
	 */
	private BcfCompensationCalculator $calc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new BcfCompensationCalculator();

	}//end setUp()

	/**
	 * Cent conversion avoids IEEE-754 drift (0.1 + 0.2 == 0.3 in cents).
	 *
	 * @return void
	 */
	public function testCentArithmeticAvoidsFloatDrift(): void {
		$sum = ($this->calc->toCents(0.1) + $this->calc->toCents(0.2));
		self::assertSame($this->calc->toCents(0.3), $sum);

	}//end testCentArithmeticAvoidsFloatDrift()

	/**
	 * Percentages clamp to the closed range 0..100 (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testClampPercentage(): void {
		self::assertSame(0, $this->calc->clampPercentage(-10));
		self::assertSame(0, $this->calc->clampPercentage('not-a-number'));
		self::assertSame(50, $this->calc->clampPercentage(50));
		self::assertSame(100, $this->calc->clampPercentage(100));
		self::assertSame(100, $this->calc->clampPercentage(250));

	}//end testClampPercentage()

	/**
	 * A 50%-weighted account yields half the compensable VAT (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testWeightedCentsHalves(): void {
		// 40000.00 EUR -> 4,000,000 cents, weighted 50% -> 2,000,000 cents.
		self::assertSame(2000000, $this->calc->weightedCents(4000000, 50));
		self::assertSame(4000000, $this->calc->weightedCents(4000000, 100));
		self::assertSame(0, $this->calc->weightedCents(4000000, 0));

	}//end testWeightedCentsHalves()

	/**
	 * Only bcfCompensable accounts with a positive percentage are compensable (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testIsCompensable(): void {
		self::assertFalse($this->calc->isCompensable(null));
		self::assertFalse($this->calc->isCompensable(['bcfCompensable' => false, 'compensablePercentage' => 100]));
		self::assertFalse($this->calc->isCompensable(['bcfCompensable' => true, 'compensablePercentage' => 0]));
		self::assertTrue($this->calc->isCompensable(['bcfCompensable' => true, 'compensablePercentage' => 50]));

	}//end testIsCompensable()

	/**
	 * The worked example from REQ-BCF-002 sums to 120,000 EUR.
	 *
	 * 3610 (100k @ 100%) + 3650 (50k, not compensable) + 4100 (40k @ 50%)
	 * = 100,000 + 0 + 20,000 = 120,000.
	 *
	 * @return void
	 */
	public function testComputeCompensationWorkedExample(): void {
		$amounts = [
			'3610' => 100000.0,
			'3650' => 50000.0,
			'4100' => 40000.0,
		];
		$mappings = [
			'3610' => ['bcfCompensable' => true, 'compensablePercentage' => 100],
			'3650' => ['bcfCompensable' => false, 'compensablePercentage' => 0],
			'4100' => ['bcfCompensable' => true, 'compensablePercentage' => 50],
		];

		$result = $this->calc->computeCompensation($amounts, $mappings);

		self::assertSame(120000.0, $result['totalCompensableAmount']);
		self::assertCount(2, $result['breakdown']);

		// Breakdown is sorted by accountNumber: 3610 then 4100.
		self::assertSame('3610', $result['breakdown'][0]['accountNumber']);
		self::assertSame(100, $result['breakdown'][0]['compensablePercentage']);
		self::assertSame(100000.0, $result['breakdown'][0]['compensableAmount']);

		self::assertSame('4100', $result['breakdown'][1]['accountNumber']);
		self::assertSame(50, $result['breakdown'][1]['compensablePercentage']);
		self::assertSame(20000.0, $result['breakdown'][1]['compensableAmount']);

	}//end testComputeCompensationWorkedExample()

	/**
	 * An all-non-compensable administration yields a zero claim with no breakdown rows.
	 *
	 * @return void
	 */
	public function testComputeCompensationAllNonCompensable(): void {
		$amounts = ['3650' => 50000.0, '3660' => 10000.0];
		$mappings = [
			'3650' => ['bcfCompensable' => false, 'compensablePercentage' => 0],
			'3660' => ['bcfCompensable' => false, 'compensablePercentage' => 100],
		];

		$result = $this->calc->computeCompensation($amounts, $mappings);

		self::assertSame(0.0, $result['totalCompensableAmount']);
		self::assertSame([], $result['breakdown']);

	}//end testComputeCompensationAllNonCompensable()

	/**
	 * Postings for accounts without any mapping are excluded (fail-closed, REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testComputeCompensationExcludesUnmappedAccounts(): void {
		$amounts = ['9999' => 80000.0];
		$mappings = [];

		$result = $this->calc->computeCompensation($amounts, $mappings);

		self::assertSame(0.0, $result['totalCompensableAmount']);
		self::assertSame([], $result['breakdown']);

	}//end testComputeCompensationExcludesUnmappedAccounts()

	/**
	 * Non-positive posting amounts are excluded from the breakdown (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testComputeCompensationExcludesNonPositiveAmounts(): void {
		$amounts = ['3610' => 0.0, '4100' => -5000.0];
		$mappings = [
			'3610' => ['bcfCompensable' => true, 'compensablePercentage' => 100],
			'4100' => ['bcfCompensable' => true, 'compensablePercentage' => 100],
		];

		$result = $this->calc->computeCompensation($amounts, $mappings);

		self::assertSame(0.0, $result['totalCompensableAmount']);
		self::assertSame([], $result['breakdown']);

	}//end testComputeCompensationExcludesNonPositiveAmounts()

	/**
	 * Submit is allowed only for a non-empty claim with a closed quarter (REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testCanSubmitPreconditions(): void {
		// Empty claim, closed quarter -> denied.
		self::assertFalse($this->calc->canSubmit(0, true));
		// Non-empty claim, open quarter -> denied.
		self::assertFalse($this->calc->canSubmit(120000.0, false));
		// Penny claim, closed quarter -> allowed (> 0).
		self::assertTrue($this->calc->canSubmit(0.01, true));
		// Real claim, closed quarter -> allowed.
		self::assertTrue($this->calc->canSubmit(120000.0, true));

	}//end testCanSubmitPreconditions()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
