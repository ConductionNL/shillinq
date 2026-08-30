<?php

/**
 * Unit tests for CrossSubsidyDetector.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\CrossSubsidyDetector;
use PHPUnit\Framework\TestCase;

/**
 * Tests the 7 cross-subsidy detection scenarios (REQ-WMO-007 + REQ-WMO-012).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CrossSubsidyDetectorTest extends TestCase {

	/**
	 * The detector under test.
	 */
	private CrossSubsidyDetector $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new CrossSubsidyDetector();

	}//end setUp()

	/**
	 * Scenario 1: two consecutive months with negative marge triggers loss-financing.
	 */
	public function testDetectLossFinancingFiresAfterTwoConsecutiveNegativeMonths(): void {
		$history = [
			['marge' => -5.0],
			['marge' => -2.0],
			['marge' => 10.0],
		];
		self::assertTrue($this->svc->detectLossFinancing($history));

		$okHistory = [
			['marge' => -5.0],
			['marge' => 2.0],
			['marge' => -3.0],
		];
		self::assertFalse($this->svc->detectLossFinancing($okHistory));

	}//end testDetectLossFinancingFiresAfterTwoConsecutiveNegativeMonths()

	/**
	 * Scenario 2: omzet > 125% prior year without IKP recalculation in current year.
	 */
	public function testDetectOmzetSpikeNoIkpUpdate(): void {
		self::assertTrue($this->svc->detectOmzetSpikeNoIkpUpdate(130_000.0, 100_000.0, '2025-12', '2026-04-01'));
		// IKP updated in current year — false.
		self::assertFalse($this->svc->detectOmzetSpikeNoIkpUpdate(130_000.0, 100_000.0, '2026-03', '2026-04-01'));
		// No spike — false.
		self::assertFalse($this->svc->detectOmzetSpikeNoIkpUpdate(110_000.0, 100_000.0, '2025-12', '2026-04-01'));

	}//end testDetectOmzetSpikeNoIkpUpdate()

	/**
	 * Scenario 3: overhead < 1% of totaleKosten triggers under-allocation alert.
	 */
	public function testDetectOverheadUnderAllocation(): void {
		$tooLow = [
			'totalCost' => 100_000.0,
			'componenten' => ['indirecteOverhead' => ['huisvesting' => 500.0]],
		];
		self::assertTrue($this->svc->detectOverheadUnderAllocation($tooLow));

		$ok = [
			'totalCost' => 100_000.0,
			'componenten' => ['indirecteOverhead' => ['huisvesting' => 5_000.0, 'ict' => 2_500.0]],
		];
		self::assertFalse($this->svc->detectOverheadUnderAllocation($ok));

	}//end testDetectOverheadUnderAllocation()

	/**
	 * Scenario 4: exempted activity with ABB volgendeEvaluatie > 2 years past triggers abb-stale.
	 */
	public function testDetectAbbStale(): void {
		$activity = ['isExempted' => true];
		$stale = ['nextEvaluation' => '2023-01-01'];
		self::assertTrue($this->svc->detectAbbStale($activity, $stale, '2026-01-15'));

		$fresh = ['nextEvaluation' => '2027-01-01'];
		self::assertFalse($this->svc->detectAbbStale($activity, $fresh, '2026-01-15'));

		// Non-exempted activities never trigger.
		self::assertFalse($this->svc->detectAbbStale(['isExempted' => false], $stale, '2026-01-15'));

	}//end testDetectAbbStale()

	/**
	 * Scenario 5: > 5% manual override rate triggers accumulation alert.
	 */
	public function testDetectManualOverrideAccumulation(): void {
		self::assertTrue($this->svc->detectManualOverrideAccumulation(7, 100));
		self::assertFalse($this->svc->detectManualOverrideAccumulation(4, 100));
		self::assertFalse($this->svc->detectManualOverrideAccumulation(0, 0));

	}//end testDetectManualOverrideAccumulation()

	/**
	 * Scenario 6: direct costs grew >20% but overhead growth lagged.
	 */
	public function testDetectOverheadOnderschatting(): void {
		// Direct +30%, overhead +5% → flag.
		self::assertTrue($this->svc->detectOverheadOnderschatting(130_000.0, 100_000.0, 21_000.0, 20_000.0));
		// Direct +30%, overhead +25% → ok.
		self::assertFalse($this->svc->detectOverheadOnderschatting(130_000.0, 100_000.0, 25_000.0, 20_000.0));
		// Direct +10% → not a spike.
		self::assertFalse($this->svc->detectOverheadOnderschatting(110_000.0, 100_000.0, 21_000.0, 20_000.0));

	}//end testDetectOverheadOnderschatting()

	/**
	 * Scenario 7 (Phase 3): tarief < median × 0.85 triggers bevoordeling-risk.
	 */
	public function testDetectBevoordelingRisk(): void {
		$benchmarks = [
			['amount' => 245.0],
			['amount' => 240.0],
			['amount' => 238.0],
		];
		// Median = 240; 200 < 240 * 0.85 = 204 → flag.
		self::assertTrue($this->svc->detectBevoordelingRisk(200.0, 180.0, $benchmarks));
		// 220 >= 204 → ok.
		self::assertFalse($this->svc->detectBevoordelingRisk(220.0, 180.0, $benchmarks));
		// Below kostprijs → caller raises non-compliance, not bevoordeling.
		self::assertFalse($this->svc->detectBevoordelingRisk(150.0, 180.0, $benchmarks));

	}//end testDetectBevoordelingRisk()

	/**
	 * Compose an alert; default assignee is concerncontroller.
	 */
	public function testComposeAlert(): void {
		$alert = $this->svc->composeAlert('loss-financing', 'ca-001', 'HIGH', 'adm-tilburg', ['period' => '2026-02']);
		self::assertSame('loss-financing', $alert['alertType']);
		self::assertSame('concerncontroller', $alert['assignedTo']);
		self::assertSame('open', $alert['status']);
		self::assertSame(['period' => '2026-02'], $alert['detectionContext']);

	}//end testComposeAlert()

	/**
	 * shouldEscalate fires when an open alert is > 4 weeks old.
	 */
	public function testShouldEscalate(): void {
		$old = ['status' => 'open', 'generatedAt' => '2026-01-01T00:00:00Z'];
		self::assertTrue($this->svc->shouldEscalate($old, '2026-02-15'));
		self::assertFalse($this->svc->shouldEscalate($old, '2026-01-10'));
		$resolved = ['status' => 'remediated', 'generatedAt' => '2026-01-01T00:00:00Z'];
		self::assertFalse($this->svc->shouldEscalate($resolved, '2026-02-15'));

	}//end testShouldEscalate()

	/**
	 * Escalation reassigns to gemeentesecretaris.
	 */
	public function testEscalate(): void {
		$alert = ['status' => 'open', 'assignedTo' => 'concerncontroller'];
		$escalated = $this->svc->escalate($alert);
		self::assertSame('escalation-due', $escalated['status']);
		self::assertSame('gemeentesecretaris', $escalated['assignedTo']);
		self::assertNotNull($escalated['escalatedAt']);

	}//end testEscalate()

	/**
	 * Resolve requires non-empty notes and a valid resolution status.
	 */
	public function testResolveValidatesInputs(): void {
		$alert = ['status' => 'open'];
		$this->expectException(InvalidArgumentException::class);
		$this->svc->resolve($alert, 'remediated', '');

	}//end testResolveValidatesInputs()

	/**
	 * Resolve produces a remediated alert with notes.
	 */
	public function testResolveHappyPath(): void {
		$alert = ['status' => 'open'];
		$resolved = $this->svc->resolve($alert, 'remediated', 'Adjusted tariff to cover IKP');
		self::assertSame('remediated', $resolved['status']);
		self::assertSame('Adjusted tariff to cover IKP', $resolved['resolutionNotes']);

	}//end testResolveHappyPath()

}//end class
