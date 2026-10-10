<?php

/**
 * Unit tests for the PurchaseOrder declarative approval-chain block.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the `x-openregister-approval-chains` block that gates the
 * PurchaseOrder `approve` transition (REQ-PAD-001), against the shape
 * OpenRegister's ApprovalChainAnnotationInstaller compiles and
 * ApprovalChainGateListener reads: `transition`, `amountField`,
 * `tiers: cumulative`, `separationOfDuties`, `onApprove` and `approvers`
 * with `role`, `min` and `minAmount`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderApprovalChainFragmentTest extends TestCase {

	/**
	 * Absolute path to the PurchaseOrder register fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json';

	/**
	 * The PurchaseOrder schema from the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function purchaseOrder(): array {
		$fragment = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertIsArray($fragment);
		return $fragment['components']['schemas']['PurchaseOrder'];
	}//end purchaseOrder()

	/**
	 * The declared chain.
	 *
	 * @return array<string, mixed>
	 */
	private function chain(): array {
		$schema = $this->purchaseOrder();
		self::assertArrayHasKey('x-openregister-approval-chains', $schema, 'PurchaseOrder must declare x-openregister-approval-chains');
		self::assertArrayHasKey('purchase-order-approval', $schema['x-openregister-approval-chains']);
		return $schema['x-openregister-approval-chains']['purchase-order-approval'];
	}//end chain()

	/**
	 * The chain gates the real `approve` transition, draft to approved.
	 *
	 * @return void
	 */
	public function testChainGatesTheApproveTransition(): void {
		self::assertSame('approve', $this->chain()['transition']);

		$transitions = $this->purchaseOrder()['x-openregister-lifecycle']['transitions'];
		self::assertSame('draft', $transitions['approve']['from']);
		self::assertSame('approved', $transitions['approve']['to']);
	}//end testChainGatesTheApproveTransition()

	/**
	 * The amount field is a real integer-cents property.
	 *
	 * @return void
	 */
	public function testChainRoutesOnTheOrderTotalInCents(): void {
		$field = $this->chain()['amountField'];
		self::assertSame('totalExclVat', $field);
		self::assertSame('integer', $this->purchaseOrder()['properties'][$field]['type']);
	}//end testChainRoutesOnTheOrderTotalInCents()

	/**
	 * Three cumulative tiers: teamleider from 1 cent, facility manager from
	 * EUR 10,000 and procurement manager from EUR 50,000 (decision 6).
	 *
	 * @return void
	 */
	public function testChainDeclaresThreeCumulativeTiers(): void {
		$chain = $this->chain();
		self::assertSame('cumulative', $chain['tiers']);

		$byAmount = [];
		foreach ($chain['approvers'] as $tier) {
			self::assertSame(1, $tier['min']);
			$byAmount[$tier['minAmount']] = $tier['role'];
		}

		self::assertSame(
			[
				1 => 'teamleider',
				1000000 => 'facility_manager',
				5000000 => 'procurement_manager',
			],
			$byAmount
		);
	}//end testChainDeclaresThreeCumulativeTiers()

	/**
	 * Separation of duties is on and completion advances the transition.
	 *
	 * @return void
	 */
	public function testChainEnforcesSeparationOfDutiesAndAdvances(): void {
		$chain = $this->chain();
		self::assertTrue($chain['separationOfDuties']);
		self::assertSame('advanceTransition', $chain['onApprove']);
	}//end testChainEnforcesSeparationOfDutiesAndAdvances()

	/**
	 * The in-object chain is history only: the inert `delegated` decision is gone.
	 *
	 * @return void
	 */
	public function testInObjectChainNoLongerOffersDelegated(): void {
		$decision = $this->purchaseOrder()['properties']['approvalChain']['items']['properties']['decision'];
		self::assertNotContains('delegated', $decision['enum']);
	}//end testInObjectChainNoLongerOffersDelegated()
}//end class
