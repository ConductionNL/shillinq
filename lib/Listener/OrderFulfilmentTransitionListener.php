<?php

/**
 * OrderFulfilment Transition Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\BudgetImpactEmitterInterface;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\TenderNedStatusSync;
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
	 * @param BudgetImpactEmitterInterface $emitter Shared cross-app CloudEvent emitter.
	 * @param TenderNedStatusSync $sync Outbound status-sync integration (REQ-006).
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly BudgetImpactEmitterInterface $emitter,
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
			$oplevering = $this->completedFulfilment(event: $event);
			if ($oplevering === null) {
				return;
			}

			// REQ-005 / Task 5.3 — always emit the milestone-completed event.
			$this->emitter->emitMilestoneCompleted(oplevering: $oplevering);

			if ($this->isApprovedEindoplevering(oplevering: $oplevering) === true) {
				$this->sync->syncCompletion(oplevering: $oplevering);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'OrderFulfilmentTransitionListener: fail-soft on completion',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * The OrderFulfilment this transition completed, or null for any other transition.
	 *
	 * @param ObjectTransitionedEvent $event OR transition event.
	 *
	 * @return array<string,mixed>|null The fulfilment.
	 */
	private function completedFulfilment(ObjectTransitionedEvent $event): array|null {
		if ($event->getTo() !== 'completed') {
			return null;
		}

		$entity = $event->getObject();
		if ($entity === null || $this->isOrderFulfilmentSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
			return null;
		}

		$oplevering = $entity->getObject();
		if (is_array($oplevering) === false) {
			return null;
		}

		return $oplevering;

	}//end completedFulfilment()

	/**
	 * Whether the fulfilment is the approved eindoplevering (REQ-006).
	 *
	 * Outbound sync only follows the buyer-side approval of the final
	 * delivery; vendor-completed openrules are denied earlier by RBAC.
	 *
	 * @param array<string,mixed> $oplevering The fulfilment.
	 *
	 * @return bool True when the completion must be synced.
	 */
	private function isApprovedEindoplevering(array $oplevering): bool {
		return (string)($oplevering['deliveryType'] ?? '') === 'eindoplevering'
			&& ((bool)($oplevering['approved'] ?? false)) === true;

	}//end isApprovedEindoplevering()

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
