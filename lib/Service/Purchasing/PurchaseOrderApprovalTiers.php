<?php

/**
 * Purchase Order Approval Tiers
 *
 * Reads the approver tiers from the PurchaseOrder schema's declared
 * `x-openregister-approval-chains` block, so the form's preview and the
 * setup wizard's groups follow the one declaration OpenRegister's gate
 * enforces. shillinq keeps no threshold table of its own
 * (purchasing-approval-delegation design.md D1 and D4).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Purchasing
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

namespace OCA\Shillinq\Service\Purchasing;

use OCP\IGroupManager;
use RuntimeException;

/**
 * The declared purchase order approver tiers, resolved cumulatively.
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.1
 */
class PurchaseOrderApprovalTiers {
	/**
	 * The register fragment that declares the PurchaseOrder schema.
	 *
	 * @var string
	 */
	public const FRAGMENT = __DIR__ . '/../../Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json';

	/**
	 * The chain key on the PurchaseOrder schema.
	 *
	 * @var string
	 */
	public const CHAIN_KEY = 'purchase-order-approval';

	/**
	 * Constructor.
	 *
	 * @param array<int, array{role: string, minAmount: int}> $tiers Tiers, lowest minAmount first.
	 */
	public function __construct(
		private readonly array $tiers,
	) {
	}//end __construct()

	/**
	 * Read the tiers from the shipped PurchaseOrder declaration.
	 *
	 * @return self
	 *
	 * @throws RuntimeException When the declaration is missing or has no approvers.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.1
	 */
	public static function fromDeclaration(): self {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$approvers = [];
		if (is_array($fragment) === true) {
			$approvers = ($fragment['components']['schemas']['PurchaseOrder']['x-openregister-approval-chains'][self::CHAIN_KEY]['approvers'] ?? []);
		}

		$tiers = [];
		foreach ((array)$approvers as $approver) {
			$role = trim((string)($approver['role'] ?? ''));
			if ($role !== '') {
				$tiers[] = ['role' => $role, 'minAmount' => (int)($approver['minAmount'] ?? 0)];
			}
		}

		if ($tiers === []) {
			throw new RuntimeException('The PurchaseOrder schema declares no approval chain approvers.');
		}

		usort($tiers, static fn (array $left, array $right): int => $left['minAmount'] <=> $right['minAmount']);

		return new self(tiers: $tiers);
	}//end fromDeclaration()

	/**
	 * The roles an order of this total must pass, in decision order.
	 *
	 * Every tier at or below the amount, as OpenRegister's gate resolves
	 * `tiers: cumulative`. A zero total needs nobody.
	 *
	 * @param int $cents The order total excluding VAT, in euro cents.
	 *
	 * @return array<int, array{role: string, order: int}>
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.1
	 */
	public function rolesFor(int $cents): array {
		if ($cents <= 0) {
			return [];
		}

		$roles = [];
		foreach ($this->tiers as $tier) {
			if ($tier['minAmount'] <= $cents) {
				$roles[] = ['role' => $tier['role'], 'order' => (count($roles) + 1)];
			}
		}

		return $roles;
	}//end rolesFor()

	/**
	 * Create every approver group that does not exist yet.
	 *
	 * OpenRegister routes each step to the Nextcloud group named by its
	 * role; a missing group leaves the step with nobody who may decide it.
	 *
	 * @param IGroupManager $groupManager Nextcloud's group manager.
	 *
	 * @return array<int, string> The groups this call created.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.1
	 */
	public function ensureGroups(IGroupManager $groupManager): array {
		$created = [];
		foreach ($this->tiers as $tier) {
			if ($groupManager->groupExists($tier['role']) === true) {
				continue;
			}

			$groupManager->createGroup($tier['role']);
			$created[] = $tier['role'];
		}

		return $created;
	}//end ensureGroups()
}//end class
