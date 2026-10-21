<?php

/**
 * Payment Controls Registration
 *
 * Wires the pre-save listeners that lock an approved payment run and guard the
 * release of a payment block.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\PaymentBlockReleaseListener;
use OCA\Shillinq\Listener\PaymentRunLineLockListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the payment controls.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
 */
final class PaymentControlsRegistration {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: PaymentRunLineLockListener::class);
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: PaymentBlockReleaseListener::class);
	}//end register()
}//end class
