<?php

/**
 * Unit tests for NexusCalculationService.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\NexusCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the OECD BEPS Action 5 modified-nexus arithmetic (REQ-IBA-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class NexusCalculationServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var NexusCalculationService
	 */
	private NexusCalculationService $svc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new NexusCalculationService();

	}//end setUp()

	/**
	 * Eigen 480k + derden 120k + verbonden 80k -> 100% (uplift not bottleneck, cap binds).
	 *
	 * Teller_voor = 600k, teller_na = min(1.3*600k, 680k) = 680k, ratio = 1.0.
	 *
	 * @return void
	 */
	public function testFullNexusWithCapBinding(): void {
		$r = $this->svc->calculateNexusBreak(480000.0, 120000.0, 80000.0);
		self::assertSame(600000.0, $r['numeratorBeforeUplift']);
		self::assertSame(680000.0, $r['numeratorAfterUplift']);
		self::assertSame(1.0, $r['nexusFractionApplied']);

	}//end testFullNexusWithCapBinding()

	/**
	 * Eigen 100k + derden 50k + verbonden 300k -> 43.33% (uplift insufficient).
	 *
	 * Teller_na = min(1.3*150k, 450k) = 195k, ratio = 195k/450k = 0.4333.
	 *
	 * @return void
	 */
	public function testPartialNexusWhenRelatedPartyDominates(): void {
		$r = $this->svc->calculateNexusBreak(100000.0, 50000.0, 300000.0);
		self::assertSame(0.4333, $r['nexusFractionApplied']);

	}//end testPartialNexusWhenRelatedPartyDominates()

	/**
	 * Eigen 10k + derden 0 + verbonden 990k -> 13% (uplift cannot reach 100%).
	 *
	 * Teller_na = min(1.3*10k, 1000k) = 13k, ratio = 13k/1000k = 0.013.
	 *
	 * @return void
	 */
	public function testLowNexusWhenAlmostAllRelatedParty(): void {
		$r = $this->svc->calculateNexusBreak(10000.0, 0.0, 990000.0);
		self::assertSame(0.013, $r['nexusFractionApplied']);

	}//end testLowNexusWhenAlmostAllRelatedParty()

	/**
	 * Zero total R&D yields a zero nexus (no division by zero).
	 *
	 * @return void
	 */
	public function testZeroTotalYieldsZeroNexus(): void {
		$r = $this->svc->calculateNexusBreak(0.0, 0.0, 0.0);
		self::assertSame(0.0, $r['nexusFractionApplied']);

	}//end testZeroTotalYieldsZeroNexus()

	/**
	 * The scenario helper returns the applied (capped) nexusbreuk (REQ-IBA-009).
	 *
	 * 500k eigen + 0 derden + 300k verbonden -> min(1.3 * 500k / 800k, 1) = 0.8125.
	 *
	 * @return void
	 */
	public function testScenarioRecalculation(): void {
		self::assertSame(0.8125, $this->svc->scenarioNexusBreak(500000.0, 0.0, 300000.0));

	}//end testScenarioRecalculation()
}//end class
