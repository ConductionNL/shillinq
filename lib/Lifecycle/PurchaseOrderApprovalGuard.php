<?php

/**
 * Purchase Order Approval Guard
 *
 * Guards the `PurchaseOrder.approve` transition (draft -> approved). A purchase
 * order is approved when its last approver signs through
 * PurchaseOrderApprovalService, which grants `purchase-order-approval` for the
 * save that moves the state. A PATCH of `statusCode` through the object API
 * carries no such grant and is refused, and so is a save whose approval chain
 * still has an entry that is not approved.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Service\ServerWriteAuthority;

/**
 * Lets a purchase order become approved only through its approval chain.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 */
final class PurchaseOrderApprovalGuard implements LifecycleGuardInterface {

	/**
	 * The grant PurchaseOrderApprovalService holds while it saves a decision.
	 *
	 * @var string
	 */
	public const PURPOSE = 'purchase-order-approval';

	/**
	 * Refusal for an approval that did not come from the approval chain.
	 *
	 * @var string
	 */
	public const MESSAGE_NOT_SIGNED = 'A purchase order is approved by its approvers. Ask each approver in the chain to approve it.';

	/**
	 * Refusal for a chain that is empty or not fully approved.
	 *
	 * @var string
	 */
	public const MESSAGE_CHAIN_INCOMPLETE = 'The purchase order cannot be approved: not every approver in its chain has approved it.';

	/**
	 * Construct the guard.
	 *
	 * @param ServerWriteAuthority $authority The request-scoped write grants.
	 */
	public function __construct(
		private readonly ServerWriteAuthority $authority,
	) {
	}//end __construct()

	/**
	 * Allow the approve transition only for a fully signed chain saved by the approval service.
	 *
	 * @param array<string, mixed> $object The purchase order as it would be saved.
	 * @param string               $action The transition, `approve`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PurchaseOrderApprovalService::isApprovedEntry() is the one reading of a signed entry.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$id = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
		if ($this->authority->holds(purpose: self::PURPOSE, objectId: $id) === false) {
			return GuardResult::deny(self::MESSAGE_NOT_SIGNED);
		}

		$chain = ($object['approvalChain'] ?? []);
		if (is_array($chain) === false || $chain === []) {
			return GuardResult::deny(self::MESSAGE_CHAIN_INCOMPLETE);
		}

		foreach ($chain as $entry) {
			if (PurchaseOrderApprovalService::isApprovedEntry(entry: $entry) === false) {
				return GuardResult::deny(self::MESSAGE_CHAIN_INCOMPLETE);
			}
		}

		return GuardResult::allow();
	}//end check()
}//end class
