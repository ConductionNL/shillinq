<?php

/**
 * Object Request Settlement Registration
 *
 * Registers the listener that mails the debtor of a settled object payment
 * request (receivables-object-request-settlement, REQ-ORS-005). Kept out of
 * Application.php, which sits at its phpmd length limit.
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\ObjectRequestSettledListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the object-request settlement listeners.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 */
final class ObjectRequestSettlementRegistration {
	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: ObjectRequestSettledListener::class
		);
	}//end register()
}//end class
