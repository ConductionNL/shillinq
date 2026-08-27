<?php

/**
 * Unit tests for BudgetProjectionService.
 *
 * Covers `budget-projection-engine` task group 5 (REQ-BPE-010): the service
 * orchestrates {@see BudgetProjectionReader} and
 * {@see BudgetProjectionCalculator} and does no arithmetic of its own —
 * every numeric value in its output is proven here to come straight from a
 * mocked calculator's return value, not from anything the service computed
 * independently.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\BudgetProjectionCalculator;
use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Service\BudgetProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the reader/calculator orchestration and response shaping.
 */
final class BudgetProjectionServiceTest extends TestCase {

	/**
	 * A one-account, 4-month context: account 1000 (`expenses`), actuals
	 * for Jan-Apr 2027.
	 *
	 * @return array<string,mixed>
	 */
	private function context(): array {
		return [
			'accounts' => ['1000' => ['accountNumber' => '1000', 'accountType' => 'expenses']],
			'windowByAccount' => [
				'1000' => ['months' => ['2027-01', '2027-02', '2027-03', '2027-04'], 'values' => [1000, 1010, 1020, 1030]],
			],
			'lastActualMonthByAccount' => ['1000' => '2027-04'],
			'ledgerGroupEntries' => [
				['id' => 'lg-1', 'slug' => 'ledger-group-x', 'memberAccountNumbers' => ['1000']],
			],
			'ledgerGroupKeyToIndex' => ['lg-1' => 0, 'ledger-group-x' => 0],
		];

	}//end context()

	/**
	 * `projectAccount()` reports an actual month's amount straight from the
	 * calculator's `metricSeries()`, and a projected month's amount straight
	 * from the calculator's `extrapolate()` return value — proving the
	 * service invents no arithmetic of its own (REQ-BPE-010).
	 *
	 * @return void
	 */
	public function testProjectAccountDelegatesArithmeticToCalculator(): void {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->method('loadContext')->willReturn($this->context());

		$calculator = $this->createMock(BudgetProjectionCalculator::class);
		$calculator->method('projectionMetric')->willReturn('netMovement');
		$calculator->method('metricSeries')->willReturn([1000, 1010, 1020, 1030]);
		$calculator->method('growthRate')->willReturn(['rate' => 0.5, 'validSteps' => 3]);
		$calculator->method('seam')->willReturnCallback(
			static function (bool $hasActual, string $month, ?string $lastActualMonth): string {
				if ($hasActual === true) {
					return 'actual';
				}

				return ($month <= (string)$lastActualMonth) ? 'unprojectable' : 'projected';
			}
		);
		$calculator->method('monthOffset')->willReturn(1);
		// A canned value that could not be produced by any real formula
		// over this fixture's inputs — if the service ever computed its
		// own number instead of relaying this one, the assertion below
		// would fail.
		$calculator->method('extrapolate')->willReturn(999999);
		$calculator->method('cumulative')->willReturn([1000, 2010, 3030, 4060, 5059999]);

		$service = new BudgetProjectionService(reader: $reader, calculator: $calculator);

		$result = $service->projectAccount('adm-1', '1000', ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05']);

		$this->assertSame(1000, $result['trend']['2027-01']['amount']);
		$this->assertSame('actual', $result['trend']['2027-01']['kind']);
		$this->assertSame('projected', $result['trend']['2027-05']['kind']);
		$this->assertSame(999999, $result['trend']['2027-05']['amount']);
		$this->assertSame(5059999, $result['cumulative']['2027-05']);

	}//end testProjectAccountDelegatesArithmeticToCalculator()

	/**
	 * When the calculator reports `insufficient-data`, the service surfaces
	 * a typed `unprojectable` result for the projected month rather than
	 * inventing an amount (REQ-BPE-004, relayed through the service).
	 *
	 * @return void
	 */
	public function testProjectAccountSurfacesUnprojectableFromCalculator(): void {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->method('loadContext')->willReturn($this->context());

		$calculator = $this->createMock(BudgetProjectionCalculator::class);
		$calculator->method('projectionMetric')->willReturn('netMovement');
		$calculator->method('metricSeries')->willReturn([1000, 1010, 1020, 1030]);
		$calculator->method('growthRate')->willReturn(['reason' => 'insufficient-data', 'validSteps' => 2]);
		$calculator->method('seam')->willReturnCallback(
			static function (bool $hasActual, string $month, ?string $lastActualMonth): string {
				if ($hasActual === true) {
					return 'actual';
				}

				return ($month <= (string)$lastActualMonth) ? 'unprojectable' : 'projected';
			}
		);
		$calculator->expects($this->never())->method('extrapolate');
		$calculator->method('cumulative')->willReturn([1000, 2010, 3030, 4060, 4060]);

		$service = new BudgetProjectionService(reader: $reader, calculator: $calculator);

		$result = $service->projectAccount('adm-1', '1000', ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05']);

		$this->assertSame('unprojectable', $result['trend']['2027-05']['kind']);
		$this->assertSame('insufficient-data', $result['trend']['2027-05']['reason']);
		$this->assertArrayNotHasKey('amount', $result['trend']['2027-05']);

	}//end testProjectAccountSurfacesUnprojectableFromCalculator()

	/**
	 * `projectAccount()` rejects an unknown account rather than silently
	 * projecting nothing.
	 *
	 * @return void
	 */
	public function testProjectAccountRejectsUnknownAccount(): void {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->method('loadContext')->willReturn($this->context());

		$service = new BudgetProjectionService(reader: $reader);

		$this->expectException(InvalidArgumentException::class);
		$service->projectAccount('adm-1', '9999', ['2027-01']);

	}//end testProjectAccountRejectsUnknownAccount()

	/**
	 * `projectGroup()` builds each member's own account envelope from a
	 * SINGLE loaded context (one `loadContext()` call), then delegates the
	 * roll-up itself to `groupProjected()` — proving group resolution does
	 * not re-trigger a `loadContext()` per member (REQ-BPE-009's query
	 * budget would otherwise be reintroduced one account at a time).
	 *
	 * @return void
	 */
	public function testProjectGroupLoadsContextOnceAndDelegatesRollup(): void {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->expects($this->once())->method('loadContext')->willReturn($this->context());

		$calculator = $this->createMock(BudgetProjectionCalculator::class);
		$calculator->method('projectionMetric')->willReturn('netMovement');
		$calculator->method('metricSeries')->willReturn([1000, 1010, 1020, 1030]);
		$calculator->method('growthRate')->willReturn(['rate' => 0.01, 'validSteps' => 3]);
		$calculator->method('seam')->willReturn('actual');
		$calculator->method('cumulative')->willReturn([1000]);
		$calculator->expects($this->atLeastOnce())
			->method('groupProjected')
			->willReturn(['kind' => 'projected', 'amount' => 424242, 'partial' => false]);

		$service = new BudgetProjectionService(reader: $reader, calculator: $calculator);

		$result = $service->projectGroup('adm-1', 'lg-1', ['2027-01']);

		$this->assertSame(424242, $result['trend']['2027-01']['amount']);
		$this->assertSame(['1000'], $result['memberAccountNumbers']);

	}//end testProjectGroupLoadsContextOnceAndDelegatesRollup()

	/**
	 * `projectGroup()` rejects an unknown group key.
	 *
	 * @return void
	 */
	public function testProjectGroupRejectsUnknownGroup(): void {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->method('loadContext')->willReturn($this->context());

		$service = new BudgetProjectionService(reader: $reader);

		$this->expectException(InvalidArgumentException::class);
		$service->projectGroup('adm-1', 'bogus-group', ['2027-01']);

	}//end testProjectGroupRejectsUnknownGroup()
}//end class
