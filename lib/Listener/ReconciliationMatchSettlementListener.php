<?php

/**
 * Settles the invoices of a reconciliation match when it is confirmed.
 *
 * REQ-BR-006 said a confirmed match makes its invoice paid; nothing consumed
 * the confirmation, so a matched invoice stayed open and dunning chased it.
 * This listener is the one settlement path for every way a match is
 * confirmed: by a person, by a matching rule or by the bank feed. It reacts
 * to OpenRegister's ObjectTransitionedEvent for the `confirm` transition of a
 * ReconciliationMatch. Fail-soft: a settlement problem is logged and never
 * undoes the confirmation.
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
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ReconciliationMatch `confirm` to InvoiceSettlementService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 */
class ReconciliationMatchSettlementListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param InvoiceSettlementService $settlement The settlement service.
	 * @param ListenerSchemaResolver   $schemas    Matches the event's schema id against the slug.
	 * @param LoggerInterface          $logger     Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly InvoiceSettlementService $settlement,
		private readonly ListenerSchemaResolver $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		if ($event->getAction() !== 'confirm') {
			return;
		}

		// OpenRegister stamps the schema id on the entity and, unless its slug
		// contract is on, on the event too; the resolver answers either form.
		if ($this->schemas->matchesSchema(entity: $event->getObject(), expectedSlug: 'ReconciliationMatch') === false) {
			return;
		}

		try {
			$match = $event->getObject()->getObject();
			if (is_array($match) === false) {
				return;
			}

			$outcomes = $this->settlement->settle(match: $match);
			$this->logger->info(
				'ReconciliationMatchSettlementListener: match settled',
				['outcomes' => $outcomes]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ReconciliationMatchSettlementListener: settlement failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()
}//end class
