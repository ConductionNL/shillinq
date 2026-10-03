<?php

/**
 * Payment Refund Requested Listener
 *
 * Hands another app's refund command to ObjectRequestCommandService, which
 * answers it on the event.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Service\ObjectRequestCommandService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers PaymentRefundRequestedEvent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
 */
class PaymentRefundRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectRequestCommandService $commands Checks and carries out the command.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectRequestCommandService $commands,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public function handle(Event $event): void {
		if ($event instanceof PaymentRefundRequestedEvent === false) {
			return;
		}

		$this->commands->refund(event: $event);
	}//end handle()
}//end class
