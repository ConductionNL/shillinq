<?php

/**
 * Golden test for BudgetProjectionCalculator::growthRate.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetProjectionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the growth-rate result per value series, floats included.
 *
 * Regenerate with GROWTH_RATE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetProjectionGrowthRateGoldenTest extends TestCase {

	/**
	 * Golden fixture path.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/fixtures/budget-projection-growth-rate.golden.json';

	/**
	 * Every series matches the golden output.
	 *
	 * @return void
	 */
	public function testRatesMatchGolden(): void {
		$calculator = new BudgetProjectionCalculator();
		$series = [
			'steady' => [100, 110, 121, 133.1],
			'withOutliers' => [100, 200, 210, 220, 50, 52, 53],
			'flatZero' => [0, 0, 0, 0],
			'fromNothing' => [0, 100, 110, 121],
			'signFlip' => [100, -50, -55, 60, 66],
			'toZero' => [100, 0, 0, 10],
			'negatives' => [-100, -110, -121],
			'tooShort' => [100],
			'empty' => [],
		];

		$actual = [];
		foreach ($series as $name => $values) {
			$actual[$name] = $calculator->growthRate($values);
		}

		if (getenv('GROWTH_RATE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));

	}//end testRatesMatchGolden()
}//end class
