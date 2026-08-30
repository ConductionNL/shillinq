<?php

/**
 * Unit tests for ProgrammaAggregator.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-24
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ProgrammaAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Programma roll-up from child Taakvelden (REQ-002, D1).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProgrammaAggregatorTest extends TestCase {

	/**
	 * The aggregator under test.
	 *
	 * @var ProgrammaAggregator
	 */
	private ProgrammaAggregator $aggregator;

	/**
	 * Set up the aggregator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->aggregator = new ProgrammaAggregator();

	}//end setUp()

	/**
	 * REQ-002 scenario: two taakvelden roll up with no rounding drift.
	 *
	 * @return void
	 */
	public function testAggregatesChildTaakveldenWithoutRoundingDrift(): void {
		$result = $this->aggregator->aggregate(
			taskFields: [
				['revenue' => 100.0, 'expenses' => 500.0],
				['revenue' => 50.0, 'expenses' => 450.0],
			]
		);

		self::assertSame(150.0, $result['revenueTotal']);
		self::assertSame(950.0, $result['expensesTotal']);
		self::assertSame(-800.0, $result['balanceBeforeMovements']);
		self::assertSame(-800.0, $result['balanceAfterMovements']);

	}//end testAggregatesChildTaakveldenWithoutRoundingDrift()

	/**
	 * REQ-002 scenario: mutatiesReserves are applied to saldoNaMutaties.
	 *
	 * @return void
	 */
	public function testSaldoNaMutatiesAppliesReserveMutation(): void {
		$result = $this->aggregator->aggregate(
			taskFields: [['revenue' => 0.0, 'expenses' => 500.0]],
			movementsReserves: 200.0
		);

		self::assertSame(-500.0, $result['balanceBeforeMovements']);
		self::assertSame(-300.0, $result['balanceAfterMovements']);

	}//end testSaldoNaMutatiesAppliesReserveMutation()

	/**
	 * An empty taakveld set yields zeroed totals.
	 *
	 * @return void
	 */
	public function testEmptyTaakveldenYieldsZeroTotals(): void {
		$result = $this->aggregator->aggregate(taskFields: []);
		self::assertSame(0.0, $result['revenueTotal']);
		self::assertSame(0.0, $result['expensesTotal']);
		self::assertSame(0.0, $result['balanceAfterMovements']);

	}//end testEmptyTaakveldenYieldsZeroTotals()

	/**
	 * Cent-level amounts sum exactly (integer-cent arithmetic).
	 *
	 * @return void
	 */
	public function testCentLevelAmountsSumExactly(): void {
		$result = $this->aggregator->aggregate(
			taskFields: [
				['revenue' => 0.10, 'expenses' => 0.0],
				['revenue' => 0.20, 'expenses' => 0.0],
			]
		);
		self::assertSame(0.30, $result['revenueTotal']);

	}//end testCentLevelAmountsSumExactly()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
