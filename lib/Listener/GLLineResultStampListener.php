<?php

/**
 * GL Line Result Stamp Listener
 *
 * Keeps the result stamps on ledger lines current (reporting-segment-results
 * REQ-RSR-002). Three moments change them:
 *
 * - a GLTransaction moves to posted: its lines get their account class and
 *   count in results;
 * - a GLTransaction moves to reversed: it, the transaction it reverses and
 *   its reversals stop counting, so an original and its reversal leave the
 *   result together;
 * - a GLLine is created under a transaction that is already posted: source
 *   documents are booked that way (MaterialiseGlTransactionAction writes the
 *   header in state posted and then the lines), so no transition fires for
 *   them.
 *
 * The write-back is a patch of the two stamp fields, made only when they
 * differ. A failure is logged and never blocks the posting.
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
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps ledger lines when their transaction posts or is reversed.
 *
 * @template-implements IEventListener<Event>
 */
class GLLineResultStampListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GlLineResultStamps     $stamps         Writes the stamps.
	 * @param ListenerSchemaResolver $schemaResolver Resolves an entity's schema slug.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly GlLineResultStamps $stamps,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a transition of a GLTransaction or the creation of a GLLine.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-segment-results/tasks.md#task-1.2
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectTransitionedEvent) {
				$this->onTransition(event: $event);
				return;
			}

			if ($event instanceof ObjectCreatedEvent) {
				$this->onCreated(event: $event);
			}
		} catch (Throwable $e) {
			$this->logger->warning('GLLineResultStampListener: result stamps not written', ['exception' => $e->getMessage()]);
		}

	}//end handle()

	/**
	 * Stamp the lines of a transaction that was posted or reversed.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return void
	 */
	private function onTransition(ObjectTransitionedEvent $event): void {
		$entity = $event->getObject();
		if ($this->schemaResolver->schemaSlug(entity: $entity) !== 'GLTransaction') {
			return;
		}

		$transactionId = ObjectIdentifier::resolve(saved: $entity);
		if ($event->getTo() === 'posted') {
			$this->stamps->stampTransaction(transactionId: $transactionId);
			return;
		}

		if ($event->getTo() === 'reversed') {
			$this->stamps->stampReversal(transactionId: $transactionId);
		}

	}//end onTransition()

	/**
	 * Stamp a line created under a transaction that is already posted.
	 *
	 * @param ObjectCreatedEvent $event The creation.
	 *
	 * @return void
	 */
	private function onCreated(ObjectCreatedEvent $event): void {
		$entity = $event->getObject();
		if ($entity === null || $this->schemaResolver->schemaSlug(entity: $entity) !== 'GLLine') {
			return;
		}

		$line = $entity->getObject();
		$line['id'] = ObjectIdentifier::resolve(saved: $entity);
		$this->stamps->stampLine(line: $line);

	}//end onCreated()
}//end class
