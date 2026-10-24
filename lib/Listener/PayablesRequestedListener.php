<?php

/**
 * Payables Requested Listener
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCA\Shillinq\Service\InboundPayableReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers PayablesRequestedEvent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 */
class PayablesRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param InboundPayableReader $payables Reads the payouts an app raised.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InboundPayableReader $payables,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function handle(Event $event): void {
		if ($event instanceof PayablesRequestedEvent === false) {
			return;
		}

		$this->payables->listFor(event: $event);
	}//end handle()
}//end class
