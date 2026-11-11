<?php

/**
 * Golden test for ActivityCostAllocationSplitter::composeOverride.
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

use OCA\Shillinq\Service\ActivityCostAllocationSplitter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the replacement allocation (or the refusal) for every override input.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CostAllocationOverrideGoldenTest extends TestCase {

	/**
	 * Every override matches the golden fixture.
	 *
	 * @return void
	 */
	public function testOverridesMatchGolden(): void {
		// No collaborator is read by composeOverride(), so none is wired.
		$splitter = (new ReflectionClass(ActivityCostAllocationSplitter::class))->newInstanceWithoutConstructor();
		$original = [
			'id' => 'alloc-1',
			'journalEntryId' => 'je-1',
			'commercialActivityId' => 'ca-1',
			'glLineId' => 'gl-1',
			'originalAmount' => '18400.5',
			'allocationKey' => 'fte',
			'postingDate' => '2026-03-31',
			'materialised' => 1,
			'administrationId' => 'adm-1',
		];
		$inputs = [
			'valid' => ['approvedBy' => ['alice', 'bob'], 'reason' => 'correctie', 'originalAllocation' => $original, 'newSplits' => [['k' => 'PUBL']]],
			'intApprovers' => ['approvedBy' => [1, '2'], 'reason' => 'r', 'originalAllocation' => ['_id' => 'legacy-1']],
			'duplicateApprover' => ['approvedBy' => ['alice', 'alice', 'bob'], 'reason' => 'r', 'originalAllocation' => []],
			'sameTwice' => ['approvedBy' => ['alice', 'alice'], 'reason' => 'r', 'originalAllocation' => []],
			'threeApprovers' => ['approvedBy' => ['a', 'b', 'c'], 'reason' => 'r', 'originalAllocation' => []],
			'oneApprover' => ['approvedBy' => 'alice', 'reason' => 'r', 'originalAllocation' => []],
			'noApprovers' => ['reason' => 'r', 'originalAllocation' => []],
			'noReason' => ['approvedBy' => ['a', 'b'], 'reason' => '', 'originalAllocation' => []],
			'missingReason' => ['approvedBy' => ['a', 'b'], 'originalAllocation' => []],
			'scalarOriginal' => ['approvedBy' => ['a', 'b'], 'reason' => 'r', 'originalAllocation' => 'alloc-x'],
		];
		$result = [];
		foreach ($inputs as $name => $input) {
			try {
				$out = $splitter->composeOverride($input);
				$stamp = $out['handmatigeOverride']['timestamp'];
				$out['handmatigeOverride']['timestamp'] = (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $stamp) === 1);
			} catch (\Throwable $e) {
				$out = ['exception' => [get_class($e), $e->getMessage()]];
			}

			$result[$name] = $out;
		}

		$file = __DIR__.'/fixtures/cost-allocation-override.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('ALLOCATION_OVERRIDE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testOverridesMatchGolden()
}//end class
