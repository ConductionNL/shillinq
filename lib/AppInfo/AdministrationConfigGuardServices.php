<?php

/**
 * Administration Config Guard Services
 *
 * Registers the pre-save check on per-administration configuration.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Listener\AdministrationManagedWriteListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers AdministrationManagedWriteListener on create and update.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */
final class AdministrationConfigGuardServices {

	/**
	 * Register the listener on both pre-save events.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (['OCA\\OpenRegister\\Event\\ObjectCreatingEvent', 'OCA\\OpenRegister\\Event\\ObjectUpdatingEvent'] as $preSaveEvent) {
			$context->registerEventListener(
				event: $preSaveEvent,
				listener: AdministrationManagedWriteListener::class
			);
		}

	}//end register()
}//end class
