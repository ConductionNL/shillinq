<?php

/**
 * Golden test for KiaSchalenLookup::resolveTier.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
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

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\KiaSchalenLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins which tier covers every amount at and around each boundary.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class KiaSchalenLookupGoldenTest extends TestCase {

	/**
	 * The first tier whose band [from, tot) holds the amount wins; from defaults to 0; an open top band has tot null.
	 *
	 * @return void
	 */
	public function testResolveTier(): void {
		$tiers  = [
			['name' => 'none', 'tot' => 2900],
			['name' => 'pct', 'from' => 2900, 'tot' => '70602'],
			['name' => 'overlap', 'from' => 3000, 'tot' => 4000],
			['name' => 'flat', 'from' => 70602, 'tot' => 130744],
			['name' => 'open', 'from' => 392230, 'tot' => null],
		];
		$lookup = new KiaSchalenLookup(new NullLogger());
		$cases  = [
			[-1, null],
			[0, 'none'],
			[2899, 'none'],
			[2900, 'pct'],
			[3500, 'pct'],
			[70601, 'pct'],
			[70602, 'flat'],
			[130743, 'flat'],
			[130744, null],
			[392229, null],
			[392230, 'open'],
			[PHP_INT_MAX, 'open'],
		];
		foreach ($cases as [$amount, $name]) {
			$tier = $lookup->resolveTier(tiers: $tiers, jaartotaal: $amount);
			self::assertSame(expected: $name, actual: ($tier['name'] ?? null), message: (string)$amount);
		}

		self::assertNull($lookup->resolveTier(tiers: [], jaartotaal: 10));
	}//end testResolveTier()
}//end class
