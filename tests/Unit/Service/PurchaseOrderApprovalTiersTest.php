<?php

/**
 * Unit tests for PurchaseOrderApprovalTiers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PurchaseOrderApprovalTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The roles an amount needs, read from the schema's chain declaration.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrderApprovalTiers
 */
final class PurchaseOrderApprovalTiersTest extends TestCase {

	/**
	 * Amounts in cents and the roles the shipped chain asks for.
	 *
	 * @return array<string,array{0:int,1:array<int,string>}>
	 */
	public static function amounts(): array {
		return [
			'zero needs nobody' => [0, []],
			'negative needs nobody' => [-500, []],
			'one cent needs a teamleider' => [1, ['teamleider']],
			'EUR 450' => [45000, ['teamleider']],
			'just below EUR 10.000' => [999999, ['teamleider']],
			'EUR 10.000 adds the facility manager' => [1000000, ['teamleider', 'facility_manager']],
			'EUR 12.500' => [1250000, ['teamleider', 'facility_manager']],
			'EUR 50.000 adds the procurement manager' => [5000000, ['teamleider', 'facility_manager', 'procurement_manager']],
		];
	}//end amounts()

	/**
	 * The shipped fragment's tiers, cumulative, in order.
	 *
	 * @param int               $cents The amount.
	 * @param array<int,string> $roles The expected roles.
	 *
	 * @return void
	 */
	#[DataProvider('amounts')]
	public function testTheShippedChainAsksForTheseRoles(int $cents, array $roles): void {
		$chain = (new PurchaseOrderApprovalTiers())->rolesFor(cents: $cents);

		self::assertSame($roles, array_column($chain, 'role'));
		self::assertSame(range(1, max(1, count($roles))), $roles === [] ? [1] : array_column($chain, 'order'));
	}//end testTheShippedChainAsksForTheseRoles()

	/**
	 * A chain without `tiers: cumulative` returns only the highest tier, as OpenRegister compiles it.
	 *
	 * @return void
	 */
	public function testAHighestModeChainReturnsOnlyTheHighestTier(): void {
		$path = $this->fragment(chain: ['approvers' => [['role' => 'b', 'minAmount' => 100], ['role' => 'a', 'minAmount' => 0]]]);

		self::assertSame([['role' => 'b', 'order' => 1]], (new PurchaseOrderApprovalTiers(fragmentPath: $path))->rolesFor(cents: 150));
		self::assertSame([['role' => 'a', 'order' => 1]], (new PurchaseOrderApprovalTiers(fragmentPath: $path))->rolesFor(cents: 50));
	}//end testAHighestModeChainReturnsOnlyTheHighestTier()

	/**
	 * A missing fragment or chain, and a tier without a role, give no roles.
	 *
	 * @return void
	 */
	public function testAMissingOrEmptyDeclarationGivesNoRoles(): void {
		self::assertSame([], (new PurchaseOrderApprovalTiers(fragmentPath: '/nonexistent.json'))->chain());
		self::assertSame([], (new PurchaseOrderApprovalTiers(fragmentPath: '/nonexistent.json'))->rolesFor(cents: 100));

		$path = $this->fragment(chain: ['tiers' => 'cumulative', 'approvers' => [['role' => ' ', 'minAmount' => 0]]]);
		self::assertSame([], (new PurchaseOrderApprovalTiers(fragmentPath: $path))->rolesFor(cents: 100));
	}//end testAMissingOrEmptyDeclarationGivesNoRoles()

	/**
	 * Write a fragment with the given chain to a temporary file.
	 *
	 * @param array<string,mixed> $chain The chain declaration.
	 *
	 * @return string The path.
	 */
	private function fragment(array $chain): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'po-tiers');
		file_put_contents(
			$path,
			(string) json_encode(['components' => ['schemas' => ['PurchaseOrder' => ['x-openregister-approval-chains' => [PurchaseOrderApprovalTiers::CHAIN_KEY => $chain]]]]])
		);

		return $path;
	}//end fragment()
}//end class
