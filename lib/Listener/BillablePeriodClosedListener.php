<?php

/**
 * Invoice Ingest Requested Listener
 *
 * Answers BillablePeriodClosedEvent: a sibling app's customer month becomes
 * a draft invoice, or a refusal the asking app records (decision 174).
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
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Event\BillablePeriodClosedEvent;
use OCA\Shillinq\Service\BillablePeriodInvoiceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers BillablePeriodClosedEvent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */
class BillablePeriodClosedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param BillablePeriodInvoiceService $ingest Drafts or refuses.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BillablePeriodInvoiceService $ingest,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function handle(Event $event): void {
		if ($event instanceof BillablePeriodClosedEvent === false) {
			return;
		}

		$this->ingest->invoicePeriod(event: $event);
	}//end handle()
}//end class
