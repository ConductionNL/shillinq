<?php

/**
 * Dual GAAP Posted Transaction Listener
 *
 * Derives the other framework's entry and writes the bridge line when a
 * GLTransaction moves to posted (REQ-DGAAP-011). Whether the transaction is
 * divergent is the service's question: one without an adjustment rule writes
 * nothing.
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
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\DualGaapDerivationService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives a posted divergent transaction under the other framework.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */
class DualGaapPostedTransactionListener implements IEventListener {

	/**
	 * @param DualGaapDerivationService       $derivation     Derives the entry.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells this app's GLTransaction from other objects.
	 * @param LoggerInterface                 $logger         Logs a transaction that could not be derived.
	 */
	public function __construct(
		private readonly DualGaapDerivationService $derivation,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive a transaction that moved to posted.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false || $event->getTo() !== 'posted') {
			return;
		}

		$object = $event->getObject();
		if ($this->schemaResolver->matchesSchema(entity: $object, expectedSlug: 'GLTransaction') === false) {
			return;
		}

		try {
			$transaction = (array)$object->getObject();
			$transaction['id'] = (string)($transaction['id'] ?? ObjectIdentifier::resolve(saved: $object));
			$transaction['state'] = 'posted';
			$result = $this->derivation->deriveForTransaction(transaction: $transaction);
		} catch (Throwable $e) {
			$this->logger->warning('DualGaapPostedTransactionListener: derivation failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($result['derived'] === false) {
			$this->logger->debug('DualGaapPostedTransactionListener: nothing derived', ['transaction' => $transaction['id'], 'reason' => $result['message']]);
		}
	}//end handle()
}//end class
