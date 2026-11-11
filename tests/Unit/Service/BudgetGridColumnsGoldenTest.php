<?php

/**
 * Golden test for BudgetGridReader::columnsFor.
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

use OCA\Shillinq\Service\BudgetGridReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the generated columns for month, quarter and year ranges.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetGridColumnsGoldenTest extends TestCase {

	/**
	 * Every column set matches the golden fixture.
	 *
	 * @return void
	 */
	public function testColumnsMatchGolden(): void {
		// No collaborator is read by columnsFor(), so none is wired.
		$reader = (new ReflectionClass(BudgetGridReader::class))->newInstanceWithoutConstructor();
		$ranges = [
			'monthsCrossYear' => ['2023-11', '2024-03', 'month'],
			'singleMonth' => ['2024-02', '2024-02', 'month'],
			'quartersUnaligned' => ['2024-02', '2024-11', 'quarter'],
			'quartersCrossYear' => ['2024-10', '2025-03', 'quarter'],
			'years' => ['2023-06', '2025-01', 'year'],
			'unknownGranularity' => ['2025-01', '2025-02', 'week'],
		];
		$result = [];
		foreach ($ranges as $name => [$start, $end, $granularity]) {
			$result[$name] = $reader->columnsFor(['start' => $start, 'end' => $end], $granularity);
		}

		$file = __DIR__.'/fixtures/budget-grid-columns.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('GRID_COLUMNS_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testColumnsMatchGolden()
}//end class
