<?php

/**
 * FieldRequirementRegistration: wires the administration-required fields check.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\FieldRequirementListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the required fields listener on both pre-save events.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
final class FieldRequirementRegistration {

	/**
	 * Register the listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(event: $event, listener: FieldRequirementListener::class);
		}

	}//end register()
}//end class
