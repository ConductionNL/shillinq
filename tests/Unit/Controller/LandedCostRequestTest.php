<?php

/**
 * Unit tests for LandedCostRequest.
 *
 * The request value object for a landed-cost allocation: the required administration and receipt reference, their identifier shape, the positive cost in cents and the value/quantity basis (defaulting to value).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-landed-cost/spec.md
 *
 * @covers \OCA\Shillinq\Controller\LandedCostRequest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LandedCostRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class LandedCostRequestTest extends TestCase {
	private function req(array $params): LandedCostRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new LandedCostRequest($request);
	}//end req()

	public function testBasisDefaultsToValue(): void {
		$this->assertSame('value', $this->req([])->basis);
		$this->assertSame('quantity', $this->req(['basis' => 'quantity'])->basis);
	}//end testBasisDefaultsToValue()

	public function testCompletenessNeedsAdministrationAndReceipt(): void {
		$this->assertTrue($this->req(['administration_id' => 'a1', 'receipt_reference' => 'GRN/1'])->isComplete());
		$this->assertFalse($this->req(['administration_id' => 'a1'])->isComplete());
		$this->assertFalse($this->req(['receipt_reference' => 'GRN/1'])->isComplete());
	}//end testCompletenessNeedsAdministrationAndReceipt()

	public function testWellFormedNeedsIdsPositiveCostAndKnownBasis(): void {
		$this->assertTrue($this->req(['administration_id' => 'a1', 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '500', 'basis' => 'quantity'])->isWellFormed());
		$this->assertFalse($this->req(['administration_id' => 'a1', 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '0'])->isWellFormed());
		$this->assertFalse($this->req(['administration_id' => 'a1', 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '500', 'basis' => 'weird'])->isWellFormed());
		$this->assertFalse($this->req(['administration_id' => "bad\tid", 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '500'])->isWellFormed());
	}//end testWellFormedNeedsIdsPositiveCostAndKnownBasis()
}//end class
