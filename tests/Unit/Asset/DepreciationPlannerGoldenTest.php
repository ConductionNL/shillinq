<?php

/**
 * Golden test for DepreciationPlanner's monthly plans.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Asset;

use DomainException;
use OCA\Shillinq\Service\Asset\DepreciationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plans for every method, with rounding, a residual value, an exhausted book value and the refusals.
 *
 * Regenerate (only for an intended change) with
 * `DEPRECIATION_PLANNER_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Asset/DepreciationPlannerGoldenTest.php`.
 */
class DepreciationPlannerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/depreciation-planner-golden.json';

	/**
	 * The plans and refusals.
	 *
	 * @return array<string,mixed>
	 */
	private static function plans(): array {
		$planner = new DepreciationPlanner();
		$out = [
			'linear-rounding' => $planner->plan('2026-11', 100000, 1000, 7, 'linear'),
			'units' => $planner->plan('2026-01', 5000, 0, 3, 'units-of-production'),
			'degressive' => $planner->plan('2025-12', 120000, 20000, 5, 'degressive', 0.3),
			'degressive-exhausted' => $planner->plan('2026-02', 1000, 990, 4, 'degressive', 0.9),
			'below-residual' => $planner->plan('2026-02', 500, 900, 2, 'linear'),
			'one-month' => $planner->plan('2026-02', 999, 0, 1, 'degressive', 0.1),
		];
		foreach ([['2026-01', 'sum-of-years', 3, 0.0], ['2026-01', 'linear', 0, 0.0], ['2026-01', 'degressive', 3, 0.0], ['26-1', 'linear', 3, 0.0]] as [$from, $method, $months, $rate]) {
			try {
				$planner->plan($from, 1000, 0, $months, $method, $rate);
				$out['refusals'][] = 'none';
			} catch (DomainException $e) {
				$out['refusals'][] = $e->getMessage();
			}
		}

		return $out;

	}//end plans()

	/**
	 * The plans match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testThePlansMatchTheGolden(): void {
		$actual = json_encode(self::plans(), JSON_PRETTY_PRINT) . "\n";
		if (getenv('DEPRECIATION_PLANNER_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testThePlansMatchTheGolden()
}//end class
