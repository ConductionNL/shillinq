<?php

/**
 * Invoice Commitment Listener
 *
 * When a supplier invoice is approved, lowers the commitment of the order it
 * was matched to (planning-commitment-year-end, REQ-PCYE-002, REQ-PCYE-003):
 * the purchase orders in `matchedPoIds` give their `poNumber`, the commitment
 * with that `sourceReference` gets an `invoiced` movement per line, and moves
 * to partially invoiced. When the invoice is marked as the last one, the
 * commitment is closed through its declared `afsluiten`, which releases the
 * remainder to the budget.
 *
 * Fail-soft: an invoice without an order, or an order without a commitment,
 * is left alone; any error is logged and the invoice approval stands.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @template-implements IEventListener<Event>
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Books an approved supplier invoice on its order's commitment.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class InvoiceCommitmentListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CommitmentInvoicing    $invoicing      The invoice to commitment booking.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug.
	 * @param LoggerInterface        $logger         Fail-soft log.
	 */
	public function __construct(
		private readonly CommitmentInvoicing $invoicing,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Book a supplier invoice that reached approved.
	 *
	 * @param Event $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.2
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false || $event->getTo() !== 'approved') {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->schemaSlug(entity: $entity) !== 'SupplierInvoice') {
			return;
		}

		$invoice = $entity->getObject();
		if (is_array($invoice) === false) {
			return;
		}

		$invoice['id'] = (string)($invoice['id'] ?? ($entity->getUuid() ?? ''));
		try {
			$this->invoicing->book(invoice: $invoice);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InvoiceCommitmentListener: the commitment was not updated',
				['invoice' => $invoice['id'], 'exception' => $e->getMessage()]
			);
		}

	}//end handle()
}//end class
