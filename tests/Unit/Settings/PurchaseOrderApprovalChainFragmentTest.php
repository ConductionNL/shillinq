<?php

/**
 * Contract test for the PurchaseOrder approval chain declaration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Checks the `purchase-order-approval` block against the shape OpenRegister's
 * ApprovalChainAnnotationInstaller compiles: a real transition and amount
 * field, cumulative tiers ordered by minAmount, roles mapped to groups, and
 * separation of duties. Also checks the old in-object engine is gone from the
 * declaration: no guard on approve and no `delegated` decision.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The PurchaseOrder schema declares OpenRegister's approval chain.
 *
 * @coversNothing
 */
final class PurchaseOrderApprovalChainFragmentTest extends TestCase {

	/**
	 * The fragment declaring PurchaseOrder.
	 *
	 * @var string
	 */
	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json';

	/**
	 * The PurchaseOrder schema.
	 *
	 * @return array<string,mixed>
	 */
	private function purchaseOrder(): array {
		$fragment = json_decode((string) file_get_contents(self::FRAGMENT), true);
		self::assertIsArray($fragment, json_last_error_msg());

		return $fragment['components']['schemas']['PurchaseOrder'];
	}//end purchaseOrder()

	/**
	 * The declared chain.
	 *
	 * @return array<string,mixed>
	 */
	private function chain(): array {
		$chains = ($this->purchaseOrder()['x-openregister-approval-chains'] ?? []);
		self::assertArrayHasKey('purchase-order-approval', $chains);

		return $chains['purchase-order-approval'];
	}//end chain()

	/**
	 * The chain gates draft -> approved, which carries no shillinq guard any more.
	 *
	 * @return void
	 */
	public function testTheChainGatesTheApproveTransition(): void {
		$chain   = $this->chain();
		$approve = $this->purchaseOrder()['x-openregister-lifecycle']['transitions']['approve'];

		self::assertSame('approve', $chain['transition']);
		self::assertSame('draft', $approve['from']);
		self::assertSame('approved', $approve['to']);
		self::assertArrayNotHasKey('requires', $approve, 'the in-object chain guard is retired');
		self::assertSame('advanceTransition', $chain['onApprove']);
		self::assertTrue($chain['separationOfDuties']);
	}//end testTheChainGatesTheApproveTransition()

	/**
	 * The amount field is a declared integer-cents property.
	 *
	 * @return void
	 */
	public function testTheChainRoutesOnTotalExclVatInCents(): void {
		$chain = $this->chain();
		$props = $this->purchaseOrder()['properties'];

		self::assertSame('totalExclVat', $chain['amountField']);
		self::assertSame('integer', $props['totalExclVat']['type']);
	}//end testTheChainRoutesOnTotalExclVatInCents()

	/**
	 * Three cumulative tiers at 1 cent, EUR 10.000 and EUR 50.000.
	 *
	 * @return void
	 */
	public function testTheThreeTiersAreCumulativeAndOrdered(): void {
		$chain = $this->chain();
		self::assertSame('cumulative', $chain['tiers']);

		$byAmount = [];
		foreach ($chain['approvers'] as $tier) {
			self::assertSame(1, $tier['min']);
			$byAmount[$tier['minAmount']] = $tier['role'];
		}

		self::assertSame([1 => 'teamleider', 1000000 => 'facility_manager', 5000000 => 'procurement_manager'], $byAmount);
		self::assertSame(array_keys($byAmount), array_values(array_map(static fn (array $tier): int => $tier['minAmount'], $chain['approvers'])), 'tiers must be in ascending order');
	}//end testTheThreeTiersAreCumulativeAndOrdered()

	/**
	 * Every approver role maps to a group that may read and update the order.
	 *
	 * @return void
	 */
	public function testEveryApproverRoleIsAGroupThatMayReadTheOrder(): void {
		$auth = $this->purchaseOrder()['authorization'];

		foreach ($this->chain()['approvers'] as $tier) {
			self::assertArrayHasKey($tier['role'], $auth['roles']);
			foreach ($auth['roles'][$tier['role']] as $group) {
				self::assertContains($group, $auth['read']);
				self::assertContains($group, $auth['update']);
			}
		}
	}//end testEveryApproverRoleIsAGroupThatMayReadTheOrder()

	/**
	 * The in-object chain is history, without the inert `delegated` value.
	 *
	 * @return void
	 */
	public function testTheInObjectChainIsHistory(): void {
		$approvalChain = $this->purchaseOrder()['properties']['approvalChain'];

		self::assertStringContainsString('History only', $approvalChain['description']);
		self::assertSame(['pending', 'approved', 'rejected'], $approvalChain['items']['properties']['decision']['enum']);
	}//end testTheInObjectChainIsHistory()
}//end class
