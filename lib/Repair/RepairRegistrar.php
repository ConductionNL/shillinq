<?php

/**
 * Container bindings of the Repair component
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Container bindings of the Repair component.
 *
 * Application::register() hands the registration context to this class, so
 * the Repair component states its own container bindings and AppInfo does not
 * name the classes behind them. The bindings are unchanged.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class RepairRegistrar {

	/**
	 * Register the Repair component's services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition root, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		// Storage seam for the Dutch-to-English value migration. The repair step
		// depends on the interface so its own logic can be exercised against a
		// fake; only this binding knows the database.
		$context->registerServiceAlias(ValueMigrationPort::class, DbValueMigrationPort::class);
	}//end register()
}//end class
