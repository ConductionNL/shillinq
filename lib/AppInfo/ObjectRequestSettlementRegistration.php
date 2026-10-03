<?php

/**
 * Object Request Settlement Registration
 *
 * Registers the listeners of receivables-object-request-settlement: the
 * debtor's receipt mail (REQ-ORS-005) and the bank match (REQ-ORS-003/004). Kept out of
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Event\PaymentCreditRequestedEvent;
use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Listener\BankLineObjectRequestListener;
use OCA\Shillinq\Listener\PaymentCreditRequestedListener;
use OCA\Shillinq\Listener\PaymentRefundRequestedListener;
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
		// The bank match: a new line is matched, a confirmed match settles (REQ-ORS-003, REQ-ORS-004).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: BankLineObjectRequestListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: BankLineObjectRequestListener::class
		);
		// Refund and credit commands from the app a request stands on (REQ-ORC-001).
		$context->registerEventListener(
			event: PaymentRefundRequestedEvent::class,
			listener: PaymentRefundRequestedListener::class
		);
		$context->registerEventListener(
			event: PaymentCreditRequestedEvent::class,
			listener: PaymentCreditRequestedListener::class
		);
	}//end register()
}//end class
