<?php

/**
 * Golden test for VarianceGate::recalculateLine.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VarianceGate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the recalculated line for every input shape and threshold choice.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VarianceLineRecalcGoldenTest extends TestCase {

	/**
	 * Every recalculated line matches the golden fixture.
	 *
	 * @return void
	 */
	public function testRecalculatedLinesMatchGolden(): void {
		// No collaborator is read by recalculateLine(), so none is wired.
		$gate = (new ReflectionClass(VarianceGate::class))->newInstanceWithoutConstructor();
		$lines = [
			'exact' => ['expectedQuantity' => 10, 'countedQuantity' => 10, 'unitCost' => 12.5],
			'smallShort' => ['expectedQuantity' => 100, 'countedQuantity' => 96, 'unitCost' => 1.0],
			'qtyOver' => ['expectedQuantity' => 100, 'countedQuantity' => 94, 'unitCost' => 1.0],
			'valueOver' => ['expectedQuantity' => 1000, 'countedQuantity' => 990, 'unitCost' => 60.0],
			'fractional' => ['expectedQuantity' => '2.333', 'countedQuantity' => '2.335', 'unitCost' => '0.335'],
			'surplus' => ['expectedQuantity' => 0, 'countedQuantity' => 3, 'unitCost' => 7.77],
			'notCounted' => ['expectedQuantity' => 5, 'unitCost' => 2.0, 'countedValue' => 9, 'requiresReason' => true],
			'noCost' => ['expectedQuantity' => 5, 'countedQuantity' => 4],
			'noExpected' => ['countedQuantity' => 4, 'unitCost' => 2.0, 'expectedValue' => 1],
			'textQuantity' => ['expectedQuantity' => 'abc', 'countedQuantity' => 4, 'unitCost' => 2.0],
			'stale' => ['expectedQuantity' => 10, 'countedQuantity' => 20, 'unitCost' => 1.0, 'quantityVariance' => 0, 'requiresReason' => false],
		];
		$result = [];
		foreach ($lines as $name => $line) {
			$result[$name] = [
				'defaults' => $gate->recalculateLine($line),
				'tight' => $gate->recalculateLine($line, 1.0, 5.0),
				'loose' => $gate->recalculateLine($line, 50.0, null),
			];
		}

		$file = __DIR__.'/fixtures/variance-line-recalc.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('VARIANCE_LINE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testRecalculatedLinesMatchGolden()
}//end class
