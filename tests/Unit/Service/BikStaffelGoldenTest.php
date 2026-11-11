<?php

/**
 * Golden test for BIKStaffelCalculator::staffel.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BIKStaffelCalculator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the staffel breakdown (or the refusal) for principals across every
 * slab boundary, with and without VAT.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BikStaffelGoldenTest extends TestCase {

	/**
	 * Every breakdown matches the golden fixture.
	 *
	 * @return void
	 */
	public function testStaffelMatchesGolden(): void {
		// No collaborator is read by staffel(), so none is wired.
		$calculator = (new ReflectionClass(BIKStaffelCalculator::class))->newInstanceWithoutConstructor();
		$principals = [
			0.0,
			0.01,
			100.0,
			266.67,
			2500.0,
			2500.01,
			3333.33,
			5000.0,
			7500.5,
			10000.0,
			10000.01,
			99999.99,
			200000.0,
			200000.01,
			1000000.0,
			5000000.0,
		];
		$result = [];
		foreach ($principals as $principal) {
			$key = number_format($principal, 2, '.', '');
			$result[$key] = [
				'offsettable' => $calculator->staffel($principal),
				'notOffsettable' => $calculator->staffel($principal, false),
				'notOffsettable9' => $calculator->staffel($principal, false, 0.09),
				'offsettableZero' => $calculator->staffel($principal, true, 0.0),
			];
		}

		foreach (['negativePrincipal' => [-0.01, true, 0.21], 'negativeVat' => [100.0, false, -0.01]] as $name => $args) {
			try {
				$result[$name] = $calculator->staffel(...$args);
			} catch (\Throwable $e) {
				$result[$name] = ['exception' => [get_class($e), $e->getMessage()]];
			}
		}

		$file = __DIR__.'/fixtures/bik-staffel.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('BIK_STAFFEL_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testStaffelMatchesGolden()
}//end class
