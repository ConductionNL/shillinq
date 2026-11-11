<?php

/**
 * Characterisation tests for UsageRatingCalculator's tier normalisation.
 *
 * Pins how a plan's price tiers are read: non-array tiers skipped, an empty
 * or missing bound read as the open top tier, prices rounded to cents, and
 * the tiers ordered by bound with the open tier last.
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
 * @spec openspec/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UsageRatingCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The tiers a plan is rated over.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class UsageRatingTiersTest extends TestCase {

	/**
	 * Normalise tiers through the calculator's private reader.
	 *
	 * @param mixed $tiers The plan's raw tiers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normalise(mixed $tiers): array {
		$calculator = (new \ReflectionClass(UsageRatingCalculator::class))->newInstanceWithoutConstructor();

		return (new \ReflectionMethod(UsageRatingCalculator::class, 'normaliseTiers'))->invoke($calculator, $tiers);
	}

	/**
	 * Golden: skipped, open, rounded and ordered tiers.
	 *
	 * @return void
	 */
	public function testTiersAreNormalisedAndOrdered(): void {
		self::assertSame(
			[
				['upTo' => 0.5, 'unitPriceCents' => 13],
				['upTo' => 100.0, 'unitPriceCents' => 10],
				['upTo' => 1000.0, 'unitPriceCents' => 8],
				['upTo' => null, 'unitPriceCents' => 5],
			],
			$this->normalise([
				['upTo' => '1000', 'unitPriceCents' => 7.5],
				['upTo' => '', 'unitPriceCents' => '5.4'],
				'not a tier',
				['upTo' => 100, 'unitPriceCents' => 10],
				['upTo' => 0.5, 'unitPriceCents' => 12.5],
			])
		);
	}

	/**
	 * No tiers, an empty list or a non-list yields none.
	 *
	 * @return void
	 */
	public function testNoTiers(): void {
		self::assertSame([], $this->normalise(null));
		self::assertSame([], $this->normalise([]));
		self::assertSame([], $this->normalise('flat'));
		self::assertSame([['upTo' => null, 'unitPriceCents' => 0]], $this->normalise([[]]));
	}
}
