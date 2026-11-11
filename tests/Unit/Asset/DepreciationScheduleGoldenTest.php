<?php

/**
 * Golden test for the rows DepreciationScheduleService writes.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Asset;

use DomainException;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationPlanner;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Pins every schedule line and journal entry of a plan, a revision, an extra
 * depreciation and the monthly and missed postings, field for field.
 *
 * Regenerate (only for an intended change) with
 * `DEPRECIATION_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Asset/DepreciationScheduleGoldenTest.php`.
 */
class DepreciationScheduleGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/depreciation-schedule-golden.json';

	private const KIOSK = '0a0e0000-0000-4000-8000-0000000000a1';

	private const PRESS = '0a0e0000-0000-4000-8000-0000000000a2';

	private InMemoryObjectServiceStub $store;

	private DepreciationScheduleService $schedules;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'FixedAsset'           => [
					['id' => self::KIOSK, 'assetNumber' => 'FA-1', 'acquisitionDate' => '2026-01-10', 'acquisitionCost' => 1200, 'usefulLifeYears' => 1, 'residualValue' => 0, 'depreciationMethod' => 'linear', 'degressiveRate' => 0.4, 'accumulatedDepreciationAccountNumber' => '0309', 'depreciationExpenseAccountNumber' => '4810', 'costCenterCode' => 'KP-1', 'status' => 'active', 'administrationId' => 'adm-1'],
					['id' => self::PRESS, 'purchaseDate' => '2025-11-01', 'purchaseCost' => 1000, 'usefulLifeMonths' => 6, 'residualValue' => 100, 'depreciationMethod' => 'degressive', 'declineRate' => 0.5, 'accumulatedDepAccountNumber' => '0319', 'depreciationExpenseAccountNumber' => '4811', 'lifecycleState' => 'active', 'administrationId' => 'adm-2'],
				],
				'DepreciationSchedule' => [],
				'JournalEntry'         => [],
			]
		);
		$engine = new LifecycleFaithfulTransitionEngine($this->store, ['FixedAsset', 'JournalEntry'], static function (): void {
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$records = new AssetRecords($this->store, new ObjectTransitionRunner(container: $container), $settings);
		$this->schedules = new DepreciationScheduleService($records, new DepreciationPlanner());

	}//end setUp()

	/**
	 * The stored rows without their generated identifiers.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema): array {
		return array_map(
			static function (array $row): array {
				$row['glTransactionRef'] = (isset($row['glTransactionRef']) === true && $row['glTransactionRef'] !== '' ? 'linked' : ($row['glTransactionRef'] ?? null));
				unset($row['id'], $row['uuid'], $row['@self'], $row['journalNumber'], $row['assetRef']);
				return $row;
			},
			$this->store->setSchema($schema)->findAll()
		);

	}//end rows()

	/**
	 * The asset record.
	 *
	 * @param string $id The asset id.
	 *
	 * @return array<string,mixed>
	 */
	private function asset(string $id): array {
		return $this->store->setSchema('FixedAsset')->find($id)->getObject();

	}//end asset()

	/**
	 * The flows' results and every row they wrote.
	 *
	 * @return array<string,mixed>
	 */
	private function flows(): array {
		$out = [];
		$out['figures'] = [$this->schedules->figures($this->asset(self::KIOSK)), $this->schedules->figures($this->asset(self::PRESS))];
		$this->schedules->ensureSchedule($this->asset(self::KIOSK));
		$this->schedules->ensureSchedule($this->asset(self::PRESS));
		$out['postMonth'] = $this->schedules->postMonth('2026-02');
		$out['missed'] = array_map(static fn (array $row): array => array_diff_key($row, ['lineId' => 0]), $this->schedules->missed($this->asset(self::PRESS), '2026-03'));
		$out['postMissed'] = count($this->schedules->postMissed($this->asset(self::PRESS), '2026-03'));
		$out['revise'] = $this->schedules->revise($this->asset(self::KIOSK), '2026-06-15', 'degressive', 18, 'langer gebruik');
		$out['extra'] = array_diff_key($this->schedules->extra($this->asset(self::KIOSK), 150.0, '2026-08-20', 'schade'), ['journalId' => 0]);
		$out['extra']['line'] = array_diff_key($out['extra']['line'], ['id' => 0, 'uuid' => 0, '@self' => 0, 'assetRef' => 0, 'glTransactionRef' => 0]);
		$out['refusals'] = [];
		foreach ([fn () => $this->schedules->revise($this->asset(self::KIOSK), '2026-02-01', 'linear', 12, 'x'), fn () => $this->schedules->revise($this->asset(self::KIOSK), '2026-09-01', 'sum-of-years', 12, 'x'), fn () => $this->schedules->revise($this->asset(self::KIOSK), '2027-09-01', 'linear', 12, 'x'), fn () => $this->schedules->extra($this->asset(self::KIOSK), 0.0, '2026-09-01', 'x'), fn () => $this->schedules->extra($this->asset(self::KIOSK), 5.0, '2026-09-01', ' '), fn () => $this->schedules->extra($this->asset(self::KIOSK), 5000.0, '2026-09-01', 'x')] as $call) {
			try {
				$call();
				$out['refusals'][] = 'none';
			} catch (DomainException $e) {
				$out['refusals'][] = $e->getMessage();
			}
		}

		$out['schedule'] = $this->rows('DepreciationSchedule');
		$out['journals'] = $this->rows('JournalEntry');
		return $out;

	}//end flows()

	/**
	 * The flows match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testTheWrittenRowsMatchTheGolden(): void {
		$actual = json_encode($this->flows(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('DEPRECIATION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testTheWrittenRowsMatchTheGolden()
}//end class
