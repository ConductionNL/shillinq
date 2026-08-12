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
			taakvelden: [
				['baten' => 100.0, 'lasten' => 500.0],
				['baten' => 50.0, 'lasten' => 450.0],
			]
		);

		self::assertSame(150.0, $result['batenTotaal']);
		self::assertSame(950.0, $result['lastenTotaal']);
		self::assertSame(-800.0, $result['saldoVoorMutaties']);
		self::assertSame(-800.0, $result['saldoNaMutaties']);

	}//end testAggregatesChildTaakveldenWithoutRoundingDrift()

	/**
	 * REQ-002 scenario: mutatiesReserves are applied to saldoNaMutaties.
	 *
	 * @return void
	 */
	public function testSaldoNaMutatiesAppliesReserveMutation(): void {
		$result = $this->aggregator->aggregate(
			taakvelden: [['baten' => 0.0, 'lasten' => 500.0]],
			mutatiesReserves: 200.0
		);

		self::assertSame(-500.0, $result['saldoVoorMutaties']);
		self::assertSame(-300.0, $result['saldoNaMutaties']);

	}//end testSaldoNaMutatiesAppliesReserveMutation()

	/**
	 * An empty taakveld set yields zeroed totals.
	 *
	 * @return void
	 */
	public function testEmptyTaakveldenYieldsZeroTotals(): void {
		$result = $this->aggregator->aggregate(taakvelden: []);
		self::assertSame(0.0, $result['batenTotaal']);
		self::assertSame(0.0, $result['lastenTotaal']);
		self::assertSame(0.0, $result['saldoNaMutaties']);

	}//end testEmptyTaakveldenYieldsZeroTotals()

	/**
	 * Cent-level amounts sum exactly (integer-cent arithmetic).
	 *
	 * @return void
	 */
	public function testCentLevelAmountsSumExactly(): void {
		$result = $this->aggregator->aggregate(
			taakvelden: [
				['baten' => 0.10, 'lasten' => 0.0],
				['baten' => 0.20, 'lasten' => 0.0],
			]
		);
		self::assertSame(0.30, $result['batenTotaal']);

	}//end testCentLevelAmountsSumExactly()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
