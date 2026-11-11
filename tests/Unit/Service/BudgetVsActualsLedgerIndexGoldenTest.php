<?php

/**
 * Golden test for the LedgerGroup index BudgetVsActualsReader builds.
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

use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the LedgerGroup index of loadContext() on a fixture with id, slug and
 * code parent keys, key collisions and unresolved parents.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetVsActualsLedgerIndexGoldenTest extends TestCase {

	/**
	 * The index matches the golden fixture.
	 *
	 * @return void
	 */
	public function testLedgerGroupIndexMatchesGolden(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$reader = new BudgetVsActualsReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new FilteredObjectServiceStub($this->data())
		);

		$context = $reader->loadContext('adm-1', []);
		$without = $reader->loadContext('adm-1', [], false);
		$result = [
			'entries' => $context['ledgerGroupEntries'],
			'keyToIndex' => $context['ledgerGroupKeyToIndex'],
			'childrenByIndex' => $context['ledgerGroupChildrenByIndex'],
			'withoutGroups' => [$without['ledgerGroupEntries'], $without['ledgerGroupKeyToIndex'], $without['ledgerGroupChildrenByIndex']],
		];

		$file = __DIR__.'/fixtures/budget-vs-actuals-ledger-index.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('BVA_INDEX_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testLedgerGroupIndexMatchesGolden()

	/**
	 * The fixture.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function data(): array {
		$group = static fn (array $g): array => array_merge(
			[
				'administrationId' => 'adm-1',
				'accountRanges' => [],
				'includedAccountNumbers' => [],
				'excludedAccountNumbers' => [],
			],
			$g
		);
		return [
			'Account' => [
				['accountNumber' => '4000', 'administrationId' => 'adm-1'],
				['accountNumber' => '4050', 'administrationId' => 'adm-1'],
				['accountNumber' => '8000', 'administrationId' => 'adm-1'],
			],
			'LedgerGroup' => [
				$group(['id' => 'g-root', 'code' => 'ROOT', 'accountRanges' => [['from' => '4000', 'to' => '4099']]]),
				$group(['@self' => ['id' => 'g-a', 'slug' => 'a'], 'code' => 'A', 'parentLedgerGroupId' => 'ROOT', 'includedAccountNumbers' => ['8000']]),
				$group(['id' => 'g-b', 'slug' => 'b', 'code' => 'g-root', 'parentLedgerGroupId' => 'a', 'excludedAccountNumbers' => ['4050']]),
				$group(['id' => 'g-c', 'code' => 'A', 'parentLedgerGroupId' => 'g-b']),
				$group(['slug' => 'slug-only', 'parentLedgerGroupId' => 'nowhere']),
				$group(['id' => 'same', 'slug' => 'same', 'parentLedgerGroupId' => '']),
				$group(['code' => 'CODE-ONLY', 'parentLedgerGroupId' => 'same']),
				$group(['id' => 'g-other', 'administrationId' => 'adm-2', 'code' => 'ROOT']),
			],
		];

	}//end data()
}//end class
