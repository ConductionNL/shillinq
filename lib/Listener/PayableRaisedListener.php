<?php

/**
 * Payable Raised Listener
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
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Service\InboundPayableService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers PayableRaisedEvent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
 */
class PayableRaisedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param InboundPayableService $payables Checks and stores the payable.
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
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function handle(Event $event): void {
		if ($event instanceof PayableRaisedEvent === false) {
			return;
		}

		$this->payables->raise(event: $event);
	}//end handle()
}//end class
