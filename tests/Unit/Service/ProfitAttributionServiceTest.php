<?php

/**
 * Unit tests for ProfitAttributionService.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ProfitAttributionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the three winsttoerekening methods (REQ-IBA-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProfitAttributionServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var ProfitAttributionService
	 */
	private ProfitAttributionService $svc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new ProfitAttributionService();

	}//end setUp()

	/**
	 * Afpelmethode: bruto 2.4M - direct 850k - routines 750k = 800k voor nexus;
	 * nexus 100% -> 800k na nexus; 800k * 0.09 = 72k Vpb impact (REQ-IBA-003).
	 *
	 * @return void
	 */
	public function testAfpelmethodeWithFullNexus(): void {
		$r = $this->svc->calculateKwalificerendeWinst(
			'per_asset_afpelmethode',
			2400000.0,
			850000.0,
			750000.0,
			1.0
		);
		self::assertSame(800000.0, $r['qualifyingProfitForNexus']);
		self::assertSame(800000.0, $r['qualifyingProfitAfterNexus']);
		self::assertSame(72000.0, $r['vpbOnInnovationShare']);
		self::assertFalse($r['forfaitairCapApplied']);

	}//end testAfpelmethodeWithFullNexus()

	/**
	 * Afpelmethode with a 43.33% nexus reduces the qualifying profit proportionally.
	 *
	 * 800k voor nexus * 0.4333 = 346 640; * 0.09 = 31 197.60.
	 *
	 * @return void
	 */
	public function testAfpelmethodeWithReducedNexus(): void {
		$r = $this->svc->calculateKwalificerendeWinst(
			'per_asset_afpelmethode',
			2400000.0,
			850000.0,
			750000.0,
			0.4333
		);
		self::assertSame(346640.0, $r['qualifyingProfitAfterNexus']);
		self::assertSame(31197.6, $r['vpbOnInnovationShare']);

	}//end testAfpelmethodeWithReducedNexus()

	/**
	 * Forfaitair: profit 200k -> 0.25*200k = 50k, capped to 25k (cap binds);
	 * nexus NOT applied; 25k * 0.09 = 2 250 (REQ-IBA-003).
	 *
	 * @return void
	 */
	public function testForfaitairCapBinds(): void {
		$r = $this->svc->calculateKwalificerendeWinst('flat_rate_25pct', 200000.0);
		self::assertSame('flat_rate_25pct', $r['method']);
		self::assertSame(25000.0, $r['qualifyingProfitAfterNexus']);
		self::assertSame(2250.0, $r['vpbOnInnovationShare']);
		self::assertTrue($r['forfaitairCapApplied']);

	}//end testForfaitairCapBinds()

	/**
	 * Forfaitair: profit 80k -> 0.25*80k = 20k, below the 25k cap (cap does not bind).
	 *
	 * @return void
	 */
	public function testForfaitairBelowCap(): void {
		$r = $this->svc->calculateKwalificerendeWinst('flat_rate_25pct', 80000.0);
		self::assertSame(20000.0, $r['qualifyingProfitAfterNexus']);
		self::assertFalse($r['forfaitairCapApplied']);

	}//end testForfaitairBelowCap()

	/**
	 * A negative residual profit is floored at zero (REQ-IBA-003 robustness).
	 *
	 * @return void
	 */
	public function testNegativeResidualFlooredAtZero(): void {
		$r = $this->svc->calculateKwalificerendeWinst(
			'per_asset_afpelmethode',
			100000.0,
			80000.0,
			50000.0,
			1.0
		);
		self::assertSame(0.0, $r['qualifyingProfitForNexus']);
		self::assertSame(0.0, $r['vpbOnInnovationShare']);

	}//end testNegativeResidualFlooredAtZero()

	/**
	 * Cost_plus applies the innovatiebox tariff at full nexus to the residual.
	 *
	 * @return void
	 */
	public function testCostPlusUsesFullNexus(): void {
		$r = $this->svc->calculateKwalificerendeWinst('cost_plus', 500000.0, 100000.0, 0.0, 0.5);
		// Cost_plus ignores the supplied nexus and uses 1.0.
		self::assertSame(1.0, $r['nexusFractionApplied']);
		self::assertSame(400000.0, $r['qualifyingProfitAfterNexus']);

	}//end testCostPlusUsesFullNexus()
}//end class
