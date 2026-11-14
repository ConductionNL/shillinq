<?php

/**
 * Assignment Rate Registration
 *
 * Registers the listener that keeps a project assignment's shown rate current
 * (sales-billable-rate-per-hour REQ-BRPH-004).
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener\Registration;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\AssignmentRateListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the assignment rate listener for created and updated objects.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 */
final class AssignmentRateRegistration {

	/**
	 * Register the listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: AssignmentRateListener::class);
		}

	}//end register()
}//end class
