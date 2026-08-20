<?php

/**
 * Intercompany Link Listener
 *
 * Change revive-gl-tax-capabilities (shillinq#418 / #446): the missing
 * trigger for the intercompany mirror + reconciliation (REQ-MA-004).
 * `IntercompanyJournalService` implemented the whole kernel and had zero
 * callers; this listener reacts to OpenRegister's `ObjectTransitionedEvent`
 * on the `IntercompanyJournalEntry` `concept -> gekoppeld` transition — the
 * transition whose own description says "the mirrored journal entry is
 * created in the destination administration" — and hands the record to
 * {@see \OCA\Shillinq\Service\IntercompanyLinkService}.
 *
 * Fail-soft, mirroring {@see GRIRClearingListener}: a downstream failure is
 * logged, never bubbled into the triggering transition.
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\IntercompanyLinkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from the IntercompanyJournalEntry `gekoppeld` transition to the
 * mirror + reconciliation.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class IntercompanyLinkListener implements IEventListener {

	/**
	 * The lifecycle state a linked pair lands in.
	 *
	 * @var string
	 */
	private const STATE_LINKED = 'gekoppeld';

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param IntercompanyLinkService $linkService The mirror + reconciliation orchestrator.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly IntercompanyLinkService $linkService,
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
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			$schema = strtolower(trim((string)$event->getSchema()));
			if ($schema !== 'intercompanyjournalentry' && $schema !== 'intercompany-journal-entry') {
				return;
			}

			if ((string)$event->getTo() !== self::STATE_LINKED) {
				return;
			}

			$entity = $event->getObject();
			$object = $entity->getObject();
			if (is_array($object) === false) {
				return;
			}

			$this->linkService->linkAndReconcile(entry: $object);
		} catch (Throwable $e) {
			$this->logger->error(
				'IntercompanyLinkListener: failed to mirror/reconcile the intercompany pair',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()
}//end class
