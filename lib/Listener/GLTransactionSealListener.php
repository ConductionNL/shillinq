<?php

/**
 * GL Transaction Seal Listener
 *
 * Seals a GLTransaction into its administration's chain when it reaches
 * `posted` (ledger-sealed-entries REQ-LSE-001, design D2). The seal is
 * written by {@see LedgerSealService::seal()} under a lock per
 * administration. A seal that cannot be written is not swallowed: the
 * exception leaves this listener, because an unsealed posted entry is the
 * gap the chain exists to rule out.
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
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seal a GLTransaction on posted.
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 */
class GLTransactionSealListener implements IEventListener {
	/**
	 * Wire the collaborators.
	 *
	 * @param LedgerSealService      $seals          The seal chain.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly LedgerSealService $seals,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seal a GLTransaction that was just posted.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the seal cannot be written.
	 *
	 * @listener-placement inline correctness — the seal must exist the moment the transaction
	 *   is posted: deferred, two posts could chain onto one head and a failed seal would leave
	 *   a posted entry outside the chain (design D2).
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-posted-transaction-is-sealed-into-its-administrations-chain-req-lse-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false || $event->getTo() !== 'posted') {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->schemaSlug(entity: $entity) !== 'GLTransaction') {
			return;
		}

		$transaction = $entity->getObject();
		$transaction['id'] = (string)($transaction['id'] ?? $entity->getUuid() ?? '');

		try {
			$this->seals->seal(transaction: $transaction);
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Shillinq: a posted transaction could not be sealed',
				['transactionId' => $transaction['id'], 'exception' => $e->getMessage()]
			);
			throw $e;
		}
	}//end handle()
}//end class
