<?php

/**
 * BBV Validation Registration
 *
 * Wires the pre-save listener that enforces the BBV programme and budget
 * mapping rules. They compare a record with its siblings, which OpenRegister's
 * per-document validations cannot express.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\BbvAllocationValidationListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the BBV validation listener.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
 */
final class BbvValidationRegistration {

	/**
	 * Register the listener on create and update.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: BbvAllocationValidationListener::class);
		}
	}//end register()
}//end class
