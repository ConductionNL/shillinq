<?php

/**
 * Golden over BudgetScenarioEvaluator's LedgerGroup index.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the dual-keyed index, its key collisions and the parent/child map.
 */
class BudgetScenarioLedgerGroupIndexTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/budget-scenario-ledger-group-index-golden.json';

	/**
	 * Rows covering @self vs top-level keys, slug == id, missing keys,
	 * non-array member lists, numeric parents, dangling and empty parents,
	 * and a later row reusing an earlier slug as its id.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function rows(): array {
		return [
			['@self' => ['id' => 'g-root', 'slug' => 'root'], 'accountRanges' => [['from' => '4000', 'to' => '4999']]],
			['id' => 'g-a', 'slug' => 'g-a', 'parentLedgerGroupId' => 'root', 'includedAccountNumbers' => ['4100']],
			['@self' => ['id' => 'g-b'], 'slug' => 'b', 'parentLedgerGroupId' => 'g-root', 'excludedAccountNumbers' => ['4200'],
				'accountRanges' => 'not-a-list'],
			['id' => 'g-c', 'parentLedgerGroupId' => 'missing'],
			['slug' => 'only-slug', 'parentLedgerGroupId' => ''],
			['id' => 7, 'slug' => 'seven', 'parentLedgerGroupId' => 7, 'includedAccountNumbers' => null],
			['id' => 'b', 'parentLedgerGroupId' => 'b'],
			[],
		];
	}//end rows()

	/**
	 * The index matches the golden recorded on the pre-refactor builder.
	 *
	 * @return void
	 */
	public function testIndexMatchesGolden(): void {
		$class = new ReflectionClass(objectOrClass: BudgetScenarioEvaluator::class);
		$evaluator = $class->newInstanceWithoutConstructor();
		$actual = $class->getMethod(name: 'buildLedgerGroupIndex')->invoke($evaluator, self::rows());

		if (getenv(name: 'SHILLINQ_RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode(value: $actual, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		self::assertSame(expected: json_decode(json: (string)file_get_contents(self::GOLDEN), associative: true), actual: $actual);
	}//end testIndexMatchesGolden()
}//end class
