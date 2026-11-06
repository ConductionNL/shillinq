<?php

/**
 * Payable Cancel Requested Listener
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
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Service\InboundPayableService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers PayableCancelRequestedEvent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
 */
class PayableCancelRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param InboundPayableService $payables Withdraws the payable.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InboundPayableService $payables,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function handle(Event $event): void {
		if ($event instanceof PayableCancelRequestedEvent === false) {
			return;
		}

		$this->payables->cancel(event: $event);
	}//end handle()
}//end class
