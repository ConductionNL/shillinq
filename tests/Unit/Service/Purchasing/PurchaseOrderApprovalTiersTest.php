<?php

/**
 * Unit tests for PurchaseOrderApprovalTiers.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Purchasing
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

namespace OCA\Shillinq\Tests\Unit\Service\Purchasing;

use OCA\Shillinq\Service\Purchasing\PurchaseOrderApprovalTiers;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The tiers are read from the shipped PurchaseOrder declaration, never from
 * a second table in PHP, and resolve the way OpenRegister's gate does with
 * `tiers: cumulative`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderApprovalTiersTest extends TestCase {

	/**
	 * EUR 450 needs one teamleider (PO-2026-041).
	 *
	 * @return void
	 */
	public function testSmallOrderNeedsATeamleader(): void {
		self::assertSame(
			[['role' => 'teamleider', 'order' => 1]],
			PurchaseOrderApprovalTiers::fromDeclaration()->rolesFor(cents: 45000)
		);
	}//end testSmallOrderNeedsATeamleader()

	/**
	 * EUR 12,500 needs a teamleider and a facility manager (PO-2026-040).
	 *
	 * @return void
	 */
	public function testLargeOrderAddsTheFacilityManager(): void {
		self::assertSame(
			[['role' => 'teamleider', 'order' => 1], ['role' => 'facility_manager', 'order' => 2]],
			PurchaseOrderApprovalTiers::fromDeclaration()->rolesFor(cents: 1250000)
		);
	}//end testLargeOrderAddsTheFacilityManager()

	/**
	 * EUR 50,000 exactly needs all three; the boundary is inclusive.
	 *
	 * @return void
	 */
	public function testFiftyThousandNeedsAllThree(): void {
		$roles = array_column(PurchaseOrderApprovalTiers::fromDeclaration()->rolesFor(cents: 5000000), 'role');
		self::assertSame(['teamleider', 'facility_manager', 'procurement_manager'], $roles);
		self::assertSame(['teamleider'], array_column(PurchaseOrderApprovalTiers::fromDeclaration()->rolesFor(cents: 999999), 'role'));
	}//end testFiftyThousandNeedsAllThree()

	/**
	 * Nothing to approve for a zero total.
	 *
	 * @return void
	 */
	public function testZeroNeedsNobody(): void {
		self::assertSame([], PurchaseOrderApprovalTiers::fromDeclaration()->rolesFor(cents: 0));
	}//end testZeroNeedsNobody()

	/**
	 * The setup wizard creates every approver group that is missing, and
	 * leaves existing ones alone.
	 *
	 * @return void
	 */
	public function testEnsureGroupsCreatesOnlyTheMissingOnes(): void {
		$created = [];
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'teamleider');
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) use (&$created): IGroup {
				$created[] = $gid;
				return $this->createMock(IGroup::class);
			}
		);

		$result = PurchaseOrderApprovalTiers::fromDeclaration()->ensureGroups(groupManager: $groups);

		self::assertSame(['facility_manager', 'procurement_manager'], $created);
		self::assertSame(['facility_manager', 'procurement_manager'], $result);
	}//end testEnsureGroupsCreatesOnlyTheMissingOnes()
}//end class
