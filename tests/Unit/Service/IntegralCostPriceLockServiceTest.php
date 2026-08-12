<?php

/**
 * Unit tests for IntegralCostPriceLockService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\IntegralCostPriceLockService;
use PHPUnit\Framework\TestCase;

/**
 * Tests year-end IKP definitief lock (REQ-WMO-002 §year-end lock).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntegralCostPriceLockServiceTest extends TestCase {

	/**
	 * Service under test.
	 */
	private IntegralCostPriceLockService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new IntegralCostPriceLockService();

	}//end setUp()

	/**
	 * Aggregating 4 quarterly voorlopig records produces a definitief YTD record.
	 */
	public function testLockAggregatesQuarterlyVoorlopig(): void {
		$voorlopig = [];
		for ($q = 1; $q <= 4; $q++) {
			$voorlopig[] = [
				'period' => '2025-Q' . $q,
				'totaleCost' => 25_000.00,
				'componenten' => [
					'directePayrollCost' => 12_000.00,
					'directeMaterialen' => 3_000.00,
					'directeDepreciations' => 2_000.00,
					'indirecteOverhead' => ['huisvesting' => 5_000.00, 'ict' => 2_000.00],
					'capitalCost' => 500.00,
					'winstopslag' => 500.00,
				],
			];
		}

		$definitief = $this->svc->lock([
			'commercialActivityId' => 'ca-001',
			'fiscalYear' => '2025',
			'voorlopigRecords' => $voorlopig,
			'signedBy' => 'accountant-user',
			'administrationId' => 'adm-tilburg',
			'verkochteUnits' => 312.0,
			'unitLabel' => 'dagdeel-zaalhuur',
			'gehanteerdRate' => 295.0,
		]);

		self::assertSame('definitief', $definitief['status']);
		self::assertSame('2025-YTD', $definitief['period']);
		self::assertSame(100_000.00, $definitief['totaleCost']);
		self::assertSame(48_000.00, $definitief['componenten']['directePayrollCost']);
		self::assertSame(20_000.00, $definitief['componenten']['indirecteOverhead']['huisvesting']);
		self::assertSame(8_000.00, $definitief['componenten']['indirecteOverhead']['ict']);
		self::assertSame('accountant-user', $definitief['definitiefSignedBy']);
		self::assertNotNull($definitief['definitiefSignedAt']);
		self::assertEqualsWithDelta(320.51, $definitief['costPricePerUnit'], 0.05);

	}//end testLockAggregatesQuarterlyVoorlopig()

	/**
	 * Lock without signed-by raises.
	 */
	public function testLockRequiresSigner(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->lock([
			'commercialActivityId' => 'ca-001',
			'fiscalYear' => '2025',
			'voorlopigRecords' => [['totaleCost' => 1.0]],
			'signedBy' => '',
			'administrationId' => 'adm',
		]);

	}//end testLockRequiresSigner()

	/**
	 * Lock without any voorlopig records raises.
	 */
	public function testLockRequiresAtLeastOneVoorlopig(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->lock([
			'commercialActivityId' => 'ca-001',
			'fiscalYear' => '2025',
			'voorlopigRecords' => [],
			'signedBy' => 'u',
			'administrationId' => 'a',
		]);

	}//end testLockRequiresAtLeastOneVoorlopig()

	/**
	 * shouldLock fires on 31 March following the fiscal year.
	 */
	public function testShouldLockFiresAtYearEndPlus3Months(): void {
		self::assertTrue($this->svc->shouldLock('2025', '2026-03-31'));
		self::assertTrue($this->svc->shouldLock('2025', '2026-04-15'));
		self::assertFalse($this->svc->shouldLock('2025', '2026-03-30'));
		self::assertFalse($this->svc->shouldLock('2025', '2026-01-01'));

	}//end testShouldLockFiresAtYearEndPlus3Months()

}//end class
