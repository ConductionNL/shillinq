<?php

/**
 * Report Schedule Controls Registration
 *
 * Wires the pre-save listener that keeps a report schedule's delivery in its
 * owner's hands.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\ReportScheduleWriteListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the report schedule controls.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 */
final class ReportScheduleControlsRegistration {

	/**
	 * Register the listener on create and update.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: ReportScheduleWriteListener::class);
		}
	}//end register()
}//end class
