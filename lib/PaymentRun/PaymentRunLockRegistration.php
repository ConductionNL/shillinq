<?php

/**
 * Payment Run Lock Registration
 *
 * Wires the pre-save listener that keeps an approved payment run immutable.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
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

namespace OCA\Shillinq\PaymentRun;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers PaymentRunLockListener on OpenRegister's two pre-save events, create first, then update.
 *
 * The events are named by string so this compiles without OpenRegister installed.
 */
final class PaymentRunLockRegistration {

	/**
	 * Register the lock listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public function register(IRegistrationContext $context): void {
		foreach (['OCA\\OpenRegister\\Event\\ObjectCreatingEvent', 'OCA\\OpenRegister\\Event\\ObjectUpdatingEvent'] as $preSaveEvent) {
			$context->registerEventListener(
				event: $preSaveEvent,
				listener: PaymentRunLockListener::class
			);
		}
	}//end register()
}//end class
