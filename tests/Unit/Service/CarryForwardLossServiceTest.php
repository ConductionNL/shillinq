<?php

/**
 * Unit tests for CarryForwardLossService.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CarryForwardLossService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the asset-specific loss carry-forward ordering (REQ-IBA-005, REQ-IBA-007).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CarryForwardLossServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CarryForwardLossService
	 */
	private CarryForwardLossService $svc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new CarryForwardLossService();

	}//end setUp()

	/**
	 * Loss 215k + profit 800k: first 215k @ 25.8% (55 470) + residual 585k @ 9%
	 * (52 650) = 108 120 total benefit; loss fully recovered (REQ-IBA-007).
	 *
	 * @return void
	 */
	public function testLossOffsetAtFullTariffThenResidualAt9Pct(): void {
		$r = $this->svc->offsetLossAgainstProfit(215000.0, 800000.0, 1.0);
		self::assertSame(215000.0, $r['lossOffset']);
		self::assertSame(55470.0, $r['lossOffsetAtFullRate']);
		self::assertSame(585000.0, $r['residualProfit']);
		self::assertSame(52650.0, $r['residualProfitAt9Pct']);
		self::assertSame(108120.0, $r['totalBenefit']);
		self::assertSame(0.0, $r['balanceAfter']);
		self::assertSame('volledig_verrekend', $r['status']);

	}//end testLossOffsetAtFullTariffThenResidualAt9Pct()

	/**
	 * Loss 100k + profit 200k: 100k @ 25.8% (25 800) + 100k @ 9% (9 000) = 34 800;
	 * higher than a pure 9% * 200k = 18 000 (REQ-IBA-007 ordering proof).
	 *
	 * @return void
	 */
	public function testLossOffsetBeatsPureNinePercent(): void {
		$r = $this->svc->offsetLossAgainstProfit(100000.0, 200000.0, 1.0);
		self::assertSame(25800.0, $r['lossOffsetAtFullRate']);
		self::assertSame(9000.0, $r['residualProfitAt9Pct']);
		self::assertSame(34800.0, $r['totalBenefit']);
		self::assertGreaterThan((200000.0 * 0.09), $r['totalBenefit']);

	}//end testLossOffsetBeatsPureNinePercent()

	/**
	 * When the loss exceeds the profit, the whole profit is absorbed and a
	 * positive open balance remains (status stays open).
	 *
	 * @return void
	 */
	public function testPartialOffsetLeavesOpenBalance(): void {
		$r = $this->svc->offsetLossAgainstProfit(300000.0, 200000.0, 1.0);
		self::assertSame(200000.0, $r['lossOffset']);
		self::assertSame(0.0, $r['residualProfit']);
		self::assertSame(100000.0, $r['balanceAfter']);
		self::assertSame('open', $r['status']);

	}//end testPartialOffsetLeavesOpenBalance()

	/**
	 * The residual is reduced by the nexusbreuk before the 9% tariff applies.
	 *
	 * Loss 0 + profit 100k @ nexus 0.5 -> 100k * 0.5 * 0.09 = 4 500.
	 *
	 * @return void
	 */
	public function testResidualRespectsNexus(): void {
		$r = $this->svc->offsetLossAgainstProfit(0.0, 100000.0, 0.5);
		self::assertSame(4500.0, $r['residualProfitAt9Pct']);

	}//end testResidualRespectsNexus()
}//end class
