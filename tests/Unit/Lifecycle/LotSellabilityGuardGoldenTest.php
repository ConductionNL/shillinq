<?php

/**
 * Golden test for LotSellabilityGuard::evaluate.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Sort\FefoSort;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full evaluation, float shapes included, per lot set.
 *
 * Regenerate with LOT_SELLABILITY_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LotSellabilityGuardGoldenTest extends TestCase {

	/**
	 * Golden fixture path.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/fixtures/lot-sellability-guard.golden.json';

	/**
	 * Every evaluation matches the golden output.
	 *
	 * @return void
	 */
	public function testEvaluationsMatchGolden(): void {
		$guard = new LotSellabilityGuard(fefoSort: new FefoSort());
		$lots = [
			['id' => 'l-1', 'lotNumber' => 'A', 'quantity' => '3.5', 'expiryDate' => '2026-06-01'],
			['@self' => ['id' => 'l-2'], 'lotNumber' => 'B', 'quantity' => 2, 'expiryDate' => '2026-04-01'],
			['id' => 'l-3', 'lotNumber' => 'C', 'quantity' => 9, 'lotStatus' => 'quarantined'],
			['id' => 'l-4', 'quantity' => 9, 'lotStatus' => 'expired', 'expiryDate' => ''],
			['id' => 'l-5', 'quantity' => 9, 'lotStatus' => 'exhausted'],
			['id' => 'l-6', 'quantity' => 9, 'expiryDate' => '2026-02-28'],
			['id' => 'l-7', 'quantity' => 9, 'lotStatus' => 'recalled'],
			['id' => 'l-8', 'quantity' => 1],
			'junk',
		];
		$actual = [
			'enough' => $guard->evaluate($lots, 6.5, '2026-03-01'),
			'epsilon' => $guard->evaluate($lots, 6.500005, '2026-03-01'),
			'short' => $guard->evaluate($lots, 10.0, '2026-03-01'),
			'expiresToday' => $guard->evaluate([['id' => 'x', 'quantity' => 1, 'expiryDate' => '2026-03-01']], 1.0, '2026-03-01'),
			'none' => $guard->evaluate([], 0.0, '2026-03-01'),
		];

		if (getenv('LOT_SELLABILITY_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));

	}//end testEvaluationsMatchGolden()
}//end class
