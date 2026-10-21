<?php

/**
 * Purchasing Controls Registration
 *
 * Wires the pre-save listener that keeps a purchase order's approval chain in
 * the hands of the approval service.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
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

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\PurchaseOrderApprovalChainListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the purchasing controls.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 */
final class PurchasingControlsRegistration {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: PurchaseOrderApprovalChainListener::class);
		}
	}//end register()
}//end class
