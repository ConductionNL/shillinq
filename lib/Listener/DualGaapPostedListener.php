<?php

/**
 * Dual GAAP Posted Listener
 *
 * When a GLTransaction takes a transition to `posted`, derives the other
 * framework's entry and writes the bridge line through
 * DualGaapDerivationService (REQ-DGAAP-011). Fail-soft: the posting stands
 * and the reason is logged when nothing could be derived.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\DualGaapDerivationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives the second framework's entry of a divergent transaction on post.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
 */
class DualGaapPostedListener implements IEventListener {

	/**
	 * @param DualGaapDerivationServiceInterface $derivation     The derivation service.
	 * @param ListenerSchemaResolverInterface    $schemaResolver Matches the GLTransaction schema.
	 * @param LoggerInterface                    $logger         Diagnostics.
	 */
	public function __construct(
		private readonly DualGaapDerivationServiceInterface $derivation,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive on a transition of a GLTransaction to posted.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public function handle(Event $event): void {
		$transaction = $this->divergentPosting(event: $event);
		if ($transaction === null) {
			return;
		}

		try {
			$result = $this->derivation->deriveOnPost(transaction: $transaction);
		} catch (Throwable $e) {
			$this->logger->warning('DualGaapPostedListener: derivation failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($result['derived'] === false) {
			$this->logger->info('DualGaapPostedListener: nothing derived', ['transaction' => $transaction['id'], 'reason' => $result['message']]);
		}
	}//end handle()

	/**
	 * The transaction of a move to posted when it is marked as divergent, else null.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The transaction with its `id`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	private function divergentPosting(Event $event): ?array {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return null;
		}

		$object = $event->getObject();
		$isPosting = ($event->getTo() === 'posted' && $this->schemaResolver->matchesSchema(entity: $object, expectedSlug: 'GLTransaction') === true);
		if ($isPosting === false) {
			return null;
		}

		$transaction = (array)$object->getObject();
		if (in_array((string)($transaction['divergenceReasonCode'] ?? ''), ['', 'NONE'], true) === true) {
			return null;
		}

		$transaction['id'] = (string)($transaction['id'] ?? $object->getUuid());

		return $transaction;
	}//end divergentPosting()
}//end class
