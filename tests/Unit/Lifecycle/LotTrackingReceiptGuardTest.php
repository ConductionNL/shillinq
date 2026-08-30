<?php

/**
 * Unit tests for LotTrackingReceiptGuard.
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
 * @spec openspec/changes/inventory-lot-batch-expiry/specs/inventory-lot-batch-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use InvalidArgumentException;
use OCA\Shillinq\Lifecycle\LotTrackingReceiptGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LotTrackingReceiptGuard.
 *
 * Covers REQ-LOT-008:
 * - Reject receipt of tracked SKU without a lot.
 * - Accept receipt of tracked SKU when a lot references the receipt.
 * - Accept receipt of non-tracked SKU regardless of lot reference.
 * - Default (field absent) means non-tracked.
 */
class LotTrackingReceiptGuardTest extends TestCase {
	/**
	 * The guard under test.
	 *
	 * @var LotTrackingReceiptGuard
	 */
	private LotTrackingReceiptGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new LotTrackingReceiptGuard();

	}//end setUp()

	/**
	 * REQ-LOT-008: tracked SKU received without a lot — must reject.
	 *
	 * @return void
	 */
	public function testRejectsTrackedSkuReceivedWithoutLot(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Lot number required/');

		$this->guard->validate(
			receipt: ['sku' => 'DV-KAT-SENIOR-2KG', 'quantity' => 100],
			product: ['sku' => 'DV-KAT-SENIOR-2KG', 'requiresLotTracking' => true],
			lots: []
		);

	}//end testRejectsTrackedSkuReceivedWithoutLot()

	/**
	 * REQ-LOT-008: tracked SKU received with a matching lot — accepts.
	 *
	 * @return void
	 */
	public function testAcceptsTrackedSkuReceivedWithMatchingLot(): void {
		$this->guard->validate(
			receipt: ['sku' => 'DV-KAT-SENIOR-2KG', 'quantity' => 100],
			product: ['sku' => 'DV-KAT-SENIOR-2KG', 'requiresLotTracking' => true],
			lots: [
				['lotNumber' => 'LOT-2026-001', 'productSku' => 'DV-KAT-SENIOR-2KG'],
			]
		);

		self::assertTrue(true);

	}//end testAcceptsTrackedSkuReceivedWithMatchingLot()

	/**
	 * REQ-LOT-008: non-tracked SKU received without a lot — accepts.
	 *
	 * @return void
	 */
	public function testAcceptsNonTrackedSkuReceivedWithoutLot(): void {
		$this->guard->validate(
			receipt: ['sku' => 'VERPAKKINGSDOOS-MEDIUM', 'quantity' => 50],
			product: ['sku' => 'VERPAKKINGSDOOS-MEDIUM', 'requiresLotTracking' => false],
			lots: []
		);

		self::assertTrue(true);

	}//end testAcceptsNonTrackedSkuReceivedWithoutLot()

	/**
	 * REQ-LOT-008: missing requiresLotTracking field treated as false (default).
	 *
	 * @return void
	 */
	public function testMissingFieldDefaultsToFalse(): void {
		$this->guard->validate(
			receipt: ['sku' => 'LEGACY-PRODUCT', 'quantity' => 25],
			product: ['sku' => 'LEGACY-PRODUCT'],
			lots: []
		);

		self::assertTrue(true);

	}//end testMissingFieldDefaultsToFalse()

	/**
	 * Unknown product (null) — guard returns silently (FK validator's concern).
	 *
	 * @return void
	 */
	public function testUnknownProductReturnsSilently(): void {
		$this->guard->validate(
			receipt: ['sku' => 'GHOST-SKU', 'quantity' => 1],
			product: null,
			lots: []
		);

		self::assertTrue(true);

	}//end testUnknownProductReturnsSilently()

	/**
	 * REQ-LOT-008: lot referencing a different SKU does NOT satisfy the guard.
	 *
	 * @return void
	 */
	public function testLotForDifferentSkuDoesNotSatisfyGuard(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->guard->validate(
			receipt: ['sku' => 'DV-KAT-SENIOR-2KG', 'quantity' => 100],
			product: ['sku' => 'DV-KAT-SENIOR-2KG', 'requiresLotTracking' => true],
			lots: [
				['lotNumber' => 'LOT-OTHER', 'productSku' => 'DV-HOND-ADULT-400G'],
			]
		);

	}//end testLotForDifferentSkuDoesNotSatisfyGuard()
}//end class
