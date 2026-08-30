<?php

/**
 * Commitment Transition Listener.
 *
 * Task 5.2 / REQ-007 — emit the cross-app `obligation.activated` CloudEvent
 * when a `bron: tenderned` Commitment transitions to `active`, so the
 * launchpad budget-utilisation widget reflects the newly committed expense
 * within 60 seconds (REQ-007 scenario).
 *
 * Two activation paths converge here:
 *
 *  - The auto-promotion path (REQ-002), where
 *    `TenderNedAwardDetectedListener` writes a fresh Commitment with
 *    `status: active` directly. OR fires `ObjectCreatedEvent` for that
 *    write, and this listener picks it up to emit the budget event.
 *  - The manual-import-and-enrich path (REQ-001 -> activeren), where an
 *    operator hand-enriches a concept Commitment and transitions it
 *    through `activeren`. OR fires `ObjectTransitionedEvent` for that
 *    change, again handled here.
 *
 * Both shapes converge in `emitIfTenderNed()`, which guards on
 * `bron == tenderned` so non-TenderNed obligations (manual / inkoop-order
 * sourced) never reach the cross-app emitter — they have their own
 * budget pathway (out of scope for this change).
 *
 * Fail-soft: any unexpected exception is logged but never bubbles up to
 * the OR write path. The OR record itself is unaffected; only the
 * cross-app notification is dropped.
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-app `obligation.activated` emitter for TenderNed-sourced
 * Commitment records (Task 5.2 / REQ-007).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 */
class CommitmentTransitionListener implements IEventListener {
	/**
	 * Construct the listener.
	 *
	 * @param BudgetImpactEmitter $emitter Shared cross-app CloudEvent emitter.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly BudgetImpactEmitter $emitter,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an OR ObjectCreatedEvent or ObjectTransitionedEvent on the
	 * Commitment schema.
	 *
	 * @param Event $event OR object lifecycle event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
	 */
	public function handle(Event $event): void {
		try {
			$payload = $this->extractActivatedCommitment(event: $event);
			if ($payload === null) {
				return;
			}

			$this->emitIfTenderNed(commitment: $payload);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CommitmentTransitionListener: emission failed — fail-soft',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Pull the activated Commitment payload from an OR event, or null
	 * when the event is irrelevant.
	 *
	 * @param Event $event OR event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractActivatedCommitment(Event $event): ?array {
		$entity = $this->resolveTargetEntity(event: $event);
		if ($entity === null) {
			return null;
		}

		$schema = $this->schemaResolver->schemaSlug(entity: $entity);
		if ($this->isCommitmentSchema(schema: $schema) === false) {
			return null;
		}

		$payload = $entity->getObject();
		if (is_array($payload) === false) {
			return null;
		}

		// ObjectCreatedEvent fires for every fresh Commitment; only emit
		// for those that are already in the active state (the REQ-002
		// auto-promotion path, design D4).
		if ($event instanceof ObjectCreatedEvent === true
			&& (string)($payload['status'] ?? '') !== 'active'
		) {
			return null;
		}

		return $payload;
	}//end extractActivatedCommitment()

	/**
	 * Resolve the carrying entity for ObjectCreatedEvent or
	 * ObjectTransitionedEvent-to-active, returning null when the event is
	 * not a relevant lifecycle hook.
	 *
	 * @param Event $event OR event.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 */
	private function resolveTargetEntity(Event $event): ?\OCA\OpenRegister\Db\ObjectEntity {
		if ($event instanceof ObjectCreatedEvent === true) {
			return $event->getObject();
		}

		if ($event instanceof ObjectTransitionedEvent === true
			&& $event->getTo() === 'active'
		) {
			return $event->getObject();
		}

		return null;
	}//end resolveTargetEntity()

	/**
	 * Emit the budget-impact event only for TenderNed-sourced obligations.
	 *
	 * @param array<string, mixed> $commitment Commitment payload.
	 *
	 * @return void
	 */
	private function emitIfTenderNed(array $commitment): void {
		if ((string)($commitment['source'] ?? '') !== 'tenderned') {
			return;
		}

		$this->emitter->emitActivated(commitment: $commitment);

	}//end emitIfTenderNed()

	/**
	 * Check whether the schema slug is Commitment.
	 *
	 * The comparison is against a lower-cased LITERAL, which is precisely the
	 * shape that stops firing silently when the schema is renamed and this
	 * string is not: no error, the listener simply never matches again. It moved
	 * from 'verplichting' to 'commitment' with the schema rename.
	 *
	 * @param string $schema Schema slug from the event.
	 *
	 * @return bool
	 */
	private function isCommitmentSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));

		// The legacy Dutch slug is still matched on purpose: objects stored
		// before the rename carry 'verplichting' until the repair step has run
		// on that instance, and matching only the new slug would make this
		// listener silently do nothing for every one of them.
		return (str_ends_with(haystack: $normalised, needle: 'commitment')
			|| str_ends_with(haystack: $normalised, needle: 'verplichting'));

	}//end isCommitmentSchema()
}//end class
