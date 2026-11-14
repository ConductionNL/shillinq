<?php

/**
 * VAT drift registration.
 *
 * Registers the listener that checks a fiscal year's filed VAT returns for
 * drift once one of its periods is closed (REQ-VPS-003).
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener\Registration;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\PeriodClosedDriftListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the period closed drift listener on the object update event.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 */
final class VatDriftRegistration {

	/**
	 * Register the listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: PeriodClosedDriftListener::class);

	}//end register()
}//end class
