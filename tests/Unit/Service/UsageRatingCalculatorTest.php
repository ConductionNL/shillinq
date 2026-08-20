<?php

/**
 * Unit tests for `UsageRatingCalculator` — flat and graduated-tier metered
 * rating (REQ-UMB-002).
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
 * @spec openspec/changes/ar-billing-completeness/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UsageRatingCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for `UsageRatingCalculator::rate()`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class UsageRatingCalculatorTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var UsageRatingCalculator
	 */
	private UsageRatingCalculator $calc;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new UsageRatingCalculator();
	}//end setUp()

	/**
	 * Flat rating: quantity x unitPriceCents.
	 *
	 * @return void
	 */
	public function testFlatRating(): void {
		$result = $this->calc->rate(
			100.0,
			['ratingMethod' => 'flat', 'unitPriceCents' => 5, 'vatRate' => 21]
		);

		self::assertSame(500, $result['costAmountCents']);
		self::assertSame(5, $result['unitPriceCents']);
		self::assertSame('flat', $result['ratingMethod']);
		self::assertSame(21.0, $result['vatRate']);
	}//end testFlatRating()

	/**
	 * Graduated rating: 12500 calls across [1000@5, 10000@3, inf@2] =
	 * 1000*5 + 9000*3 + 2500*2 = 5000 + 27000 + 5000 = 37000 cents.
	 *
	 * @return void
	 */
	public function testGraduatedTierRating(): void {
		$result = $this->calc->rate(
			12500.0,
			[
				'ratingMethod' => 'graduated',
				'vatRate' => 21,
				'tiers' => [
					['upTo' => 1000, 'unitPriceCents' => 5],
					['upTo' => 10000, 'unitPriceCents' => 3],
					['upTo' => null, 'unitPriceCents' => 2],
				],
			]
		);

		self::assertSame(37000, $result['costAmountCents']);
		self::assertNull($result['unitPriceCents']);
		self::assertSame('graduated', $result['ratingMethod']);
		self::assertSame(12500.0, $result['billableUnits']);
	}//end testGraduatedTierRating()

	/**
	 * Tiers supplied out of order are normalised ascending before rating, and a
	 * quantity entirely within the first tier is priced at the first-tier rate.
	 *
	 * @return void
	 */
	public function testTiersAreSortedAndPartialFirstTier(): void {
		$result = $this->calc->rate(
			800.0,
			[
				'tiers' => [
					['upTo' => null, 'unitPriceCents' => 2],
					['upTo' => 10000, 'unitPriceCents' => 3],
					['upTo' => 1000, 'unitPriceCents' => 5],
				],
			]
		);

		// 800 units all fall in the first (1000@5) tier once sorted.
		self::assertSame(4000, $result['costAmountCents']);
		self::assertSame('graduated', $result['ratingMethod']);
	}//end testTiersAreSortedAndPartialFirstTier()

	/**
	 * A plan with tiers but no explicit method defaults to graduated; a plan
	 * with neither defaults to flat at zero.
	 *
	 * @return void
	 */
	public function testMethodInferenceAndZeroQuantity(): void {
		$graduated = $this->calc->rate(500.0, ['tiers' => [['upTo' => null, 'unitPriceCents' => 4]]]);
		self::assertSame('graduated', $graduated['ratingMethod']);
		self::assertSame(2000, $graduated['costAmountCents']);

		$zero = $this->calc->rate(0.0, ['unitPriceCents' => 10]);
		self::assertSame('flat', $zero['ratingMethod']);
		self::assertSame(0, $zero['costAmountCents']);
	}//end testMethodInferenceAndZeroQuantity()
}//end class
