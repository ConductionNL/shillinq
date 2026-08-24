<?php

/**
 * OrderFulfilment Transition Listener.
 *
 * Task 5.3 / REQ-005 / REQ-006 — emit the cross-app `milestone.completed`
 * CloudEvent and trigger the outbound status-sync to TenderNed when an
 * OrderFulfilment transitions to `completed` AND the eindoplevering of
 * a TenderNed-sourced obligation is approved.
 *
 * Two responsibilities, both fail-soft:
 *
 *  1. {@see BudgetImpactEmitter::emitMilestoneCompleted()} publishes the
 *     `shillinq.milestone.completed` event so the audit-trail subscriber
 *     (REQ-005) and any budget-utilisation consumer pick up the change
 *     within seconds.
 *  2. When the completed oplevering is `eindoplevering` + `approved`
 *     true, {@see TenderNedStatusSync::syncCompletion()} is invoked so
 *     the public TenderNed dossier reflects completion (REQ-006). The
 *     sync itself is best-effort (live openconnector transport, Task
 *     0.2 / 6.1).
 *
 * The listener layers on top of the declarative
 * `OrderFulfilment.voltooien` transition, which `OrderFulfilmentGuard`
 * has already gated on at least one bewijsstuk being attached (REQ-004).
 * By the time the event fires the precondition has held; the listener's
 * job is purely cross-cutting notification + outbound sync.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Integration\TenderNedStatusSync;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-app emitter + outbound status-sync dispatcher for completed
 * OrderFulfilment records (Task 5.3 / REQ-006).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 */
class OrderFulfilmentTransitionListener implements IEventListener {
	/**
	 * Construct the listener.
	 *
	 * @param BudgetImpactEmitter $emitter Shared cross-app CloudEvent emitter.
	 * @param TenderNedStatusSync $sync Outbound status-sync integration (REQ-006).
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly BudgetImpactEmitter $emitter,
		private readonly TenderNedStatusSync $sync,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent on the OrderFulfilment schema.
	 *
	 * @param Event $event OR transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			if ($event->getTo() !== 'completed') {
				return;
			}

			$entity = $event->getObject();
			if ($entity === null) {
				return;
			}

			$schema = $this->schemaResolver->schemaSlug(entity: $entity);
			if ($this->isOrderFulfilmentSchema(schema: $schema) === false) {
				return;
			}

			$oplevering = $entity->getObject();
			if (is_array($oplevering) === false) {
				return;
			}

			// REQ-005 / Task 5.3 — always emit the milestone-completed event.
			$this->emitter->emitMilestoneCompleted(oplevering: $oplevering);

			// REQ-006 — outbound sync only when this is the approved
			// eindoplevering (the buyer-side gate; vendor-completed
			// openrules are denied earlier by RBAC).
			if ((string)($oplevering['deliveryType'] ?? '') !== 'eindoplevering') {
				return;
			}

			if (((bool)($oplevering['approved'] ?? false)) !== true) {
				return;
			}

			$this->sync->syncCompletion(oplevering: $oplevering);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OrderFulfilmentTransitionListener: fail-soft on completion',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Check whether the schema slug is OrderFulfilment.
	 *
	 * Compares against a lower-cased LITERAL — the shape that stops firing
	 * silently if the schema is renamed and this string is not. It moved from
	 * 'opdrachtuitvoering' to 'orderfulfilment' with the schema rename.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return bool
	 */
	private function isOrderFulfilmentSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));

		// The legacy Dutch slug is still matched on purpose: objects stored
		// before the rename carry 'opdrachtuitvoering' until the repair step
		// has run on that instance, and matching only the new slug would make
		// this listener silently do nothing for every one of them.
		return (str_ends_with(haystack: $normalised, needle: 'orderfulfilment')
			|| str_ends_with(haystack: $normalised, needle: 'opdrachtuitvoering'));

	}//end isOrderFulfilmentSchema()
}//end class
