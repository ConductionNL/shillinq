<?php

/**
 * Unit tests for LotSellabilityGuard.
 *
 * Proves the block-unsellable-stock-dispatch correctness rule at the decision
 * level: a lot is sellable iff lotStatus == 'active' AND (expiryDate empty OR
 * >= today); quarantined / expired-by-status / exhausted / expired-by-date
 * lots are unsellable; a line is satisfiable iff summed sellable quantity
 * covers the required quantity; sellable lots are reported FEFO-ordered.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/block-unsellable-stock-dispatch/specs/block-unsellable-stock-dispatch/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Sort\FefoSort;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LotSellabilityGuard::evaluate().
 */
class LotSellabilityGuardTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * The guard under test.
	 *
	 * @var LotSellabilityGuard
	 */
	private LotSellabilityGuard $guard;

	/**
	 * Build the guard with a real FefoSort.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = new LotSellabilityGuard(fefoSort: new FefoSort());

	}//end setUp()

	/**
	 * A single active, non-expired lot with enough quantity is sellable.
	 *
	 * @return void
	 */
	public function testActiveLotWithEnoughQuantityIsSellable(): void {
		$verdict = $this->guard->evaluate(
			lots: [['id' => 'l1', 'lotNumber' => 'L1', 'lotStatus' => 'active', 'quantity' => 20, 'expiryDate' => '2099-01-01']],
			requiredQuantity: 5.0,
			today: '2026-07-14'
		);

		self::assertTrue($verdict['sellable']);
		self::assertSame(20.0, $verdict['availableSellable']);
		self::assertSame(['l1'], $verdict['sellableLotIds']);
		self::assertCount(0, $verdict['offendingLots']);

	}//end testActiveLotWithEnoughQuantityIsSellable()

	/**
	 * Quarantined, expired-by-status and exhausted lots are all unsellable.
	 *
	 * @return void
	 */
	public function testNonActiveStatusesAreUnsellable(): void {
		foreach (['quarantined', 'expired', 'exhausted'] as $status) {
			$verdict = $this->guard->evaluate(
				lots: [['id' => 'l', 'lotNumber' => 'L', 'lotStatus' => $status, 'quantity' => 99, 'expiryDate' => '2099-01-01']],
				requiredQuantity: 1.0,
				today: '2026-07-14'
			);
			self::assertFalse($verdict['sellable'], $status . ' must be unsellable');
			self::assertSame(0.0, $verdict['availableSellable']);
			self::assertSame($status, $verdict['offendingLots'][0]['lotStatus']);
		}

	}//end testNonActiveStatusesAreUnsellable()

	/**
	 * An active lot whose expiryDate is before today is unsellable (expiry
	 * first-class), and the reason names the past date.
	 *
	 * @return void
	 */
	public function testActiveButExpiredByDateIsUnsellable(): void {
		$verdict = $this->guard->evaluate(
			lots: [['id' => 'l', 'lotNumber' => 'L', 'lotStatus' => 'active', 'quantity' => 99, 'expiryDate' => '2026-06-15']],
			requiredQuantity: 1.0,
			today: '2026-07-14'
		);

		self::assertFalse($verdict['sellable']);
		self::assertStringContainsString('past expiry date 2026-06-15', $verdict['offendingLots'][0]['reason']);
		self::assertStringContainsString('2026-06-15', $verdict['offendingLots'][0]['reasonNl']);

	}//end testActiveButExpiredByDateIsUnsellable()

	/**
	 * A lot expiring exactly today is still sellable (expiry is exclusive:
	 * unsellable only when today > expiryDate).
	 *
	 * @return void
	 */
	public function testLotExpiringTodayIsStillSellable(): void {
		$verdict = $this->guard->evaluate(
			lots: [['id' => 'l', 'lotNumber' => 'L', 'lotStatus' => 'active', 'quantity' => 3, 'expiryDate' => '2026-07-14']],
			requiredQuantity: 2.0,
			today: '2026-07-14'
		);

		self::assertTrue($verdict['sellable']);

	}//end testLotExpiringTodayIsStillSellable()

	/**
	 * Sellable quantity is summed across multiple sellable lots; a null-expiry
	 * lot is sellable and sorts last (FEFO) while dated lots come first.
	 *
	 * @return void
	 */
	public function testSummedSellableQuantityCoversLineAndReportsFefoOrder(): void {
		$verdict = $this->guard->evaluate(
			lots: [
				['id' => 'late', 'lotNumber' => 'LATE', 'lotStatus' => 'active', 'quantity' => 4, 'expiryDate' => '2027-12-01'],
				['id' => 'none', 'lotNumber' => 'NONE', 'lotStatus' => 'active', 'quantity' => 4, 'expiryDate' => null],
				['id' => 'early', 'lotNumber' => 'EARLY', 'lotStatus' => 'active', 'quantity' => 4, 'expiryDate' => '2027-01-01'],
			],
			requiredQuantity: 10.0,
			today: '2026-07-14'
		);

		self::assertTrue($verdict['sellable']);
		self::assertSame(12.0, $verdict['availableSellable']);
		self::assertSame(['early', 'late', 'none'], $verdict['sellableLotIds']);

	}//end testSummedSellableQuantityCoversLineAndReportsFefoOrder()

	/**
	 * When sellable lots cannot cover the line, the verdict is blocked with a
	 * positive shortfall and the unsellable lots reported as offending.
	 *
	 * @return void
	 */
	public function testInsufficientSellableQuantityIsBlockedWithShortfall(): void {
		$verdict = $this->guard->evaluate(
			lots: [
				['id' => 'good', 'lotNumber' => 'GOOD', 'lotStatus' => 'active', 'quantity' => 3, 'expiryDate' => '2027-01-01'],
				['id' => 'bad', 'lotNumber' => 'BAD', 'lotStatus' => 'quarantined', 'quantity' => 100, 'expiryDate' => '2027-01-01'],
			],
			requiredQuantity: 5.0,
			today: '2026-07-14'
		);

		self::assertFalse($verdict['sellable']);
		self::assertSame(3.0, $verdict['availableSellable']);
		self::assertSame(2.0, $verdict['shortfall']);
		self::assertSame('BAD', $verdict['offendingLots'][0]['lotNumber']);

	}//end testInsufficientSellableQuantityIsBlockedWithShortfall()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
