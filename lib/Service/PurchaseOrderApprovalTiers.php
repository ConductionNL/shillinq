<?php

/**
 * Purchase Order Approval Tiers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads the approver tiers of the `purchase-order-approval` chain that the
 * PurchaseOrder schema declares (x-openregister-approval-chains) and answers
 * which roles an amount needs. OpenRegister provisions and gates the real
 * steps from the same declaration; this class only lets the order form show
 * who will have to approve before the order exists, so the preview and the
 * chain can never disagree. It reads no storage beyond the shipped fragment.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

/**
 * The roles a purchase order amount needs, from the schema's chain declaration.
 */
class PurchaseOrderApprovalTiers {

	/**
	 * The chain key on the PurchaseOrder schema.
	 *
	 * @var string
	 */
	public const CHAIN_KEY = 'purchase-order-approval';

	/**
	 * The fragment that declares the PurchaseOrder schema.
	 *
	 * @var string
	 */
	private const FRAGMENT = __DIR__ . '/../Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json';

	/**
	 * Constructor.
	 *
	 * @param string $fragmentPath The fragment to read; the shipped one by default.
	 */
	public function __construct(
		private readonly string $fragmentPath = self::FRAGMENT,
	) {
	}//end __construct()

	/**
	 * The declared chain, or an empty array when it is missing or unreadable.
	 *
	 * @return array<string,mixed> The chain declaration.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function chain(): array {
		if (is_file($this->fragmentPath) === false) {
			return [];
		}

		$fragment = json_decode((string) file_get_contents($this->fragmentPath), true);
		$chain    = ($fragment['components']['schemas']['PurchaseOrder']['x-openregister-approval-chains'][self::CHAIN_KEY] ?? null);

		if (is_array($chain) === false) {
			return [];
		}

		return $chain;
	}//end chain()

	/**
	 * The roles an amount in euro cents needs, in approval order.
	 *
	 * Cumulative tiers return every tier at or below the amount; any other
	 * mode returns only the highest one, as OpenRegister compiles it. An
	 * amount of zero or less needs no approver.
	 *
	 * @param int $cents The order total excluding VAT, in euro cents.
	 *
	 * @return array<int,array{role:string,order:int}> The ordered roles.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function rolesFor(int $cents): array {
		if ($cents <= 0) {
			return [];
		}

		$chain = $this->chain();
		$tiers = [];
		foreach ((array) ($chain['approvers'] ?? []) as $tier) {
			$role = trim((string) ($tier['role'] ?? ''));
			if ($role === '' || $cents < (int) ($tier['minAmount'] ?? 0)) {
				continue;
			}

			$tiers[] = ['role' => $role, 'minAmount' => (int) ($tier['minAmount'] ?? 0)];
		}

		usort($tiers, static fn (array $left, array $right): int => ($left['minAmount'] <=> $right['minAmount']));

		if (($chain['tiers'] ?? 'highest') !== 'cumulative' && $tiers !== []) {
			$tiers = [end($tiers)];
		}

		$roles = [];
		foreach ($tiers as $index => $tier) {
			$roles[] = ['role' => $tier['role'], 'order' => ($index + 1)];
		}

		return $roles;
	}//end rolesFor()
}//end class
