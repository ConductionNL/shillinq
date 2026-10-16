<?php

/**
 * Project Hours Registration
 *
 * Registers the listener that keeps an assignment's hours budget current
 * (people-hours-budget): hour records created, changed or deleted, and a
 * changed estimate on an assignment.
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
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\AssignmentHoursListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the assignment hours listener for the three object events.
 *
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 */
final class ProjectHoursRegistration {

	/**
	 * Register the listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: AssignmentHoursListener::class);
		}

	}//end register()
}//end class
