<?php

/**
 * Bank Line Object Request Listener
 *
 * Two ends of the bank match of object payment requests (REQ-ORS-003,
 * REQ-ORS-004, design D3 and D4). On a new BankStatementLine, from a file
 * import or a connected bank account, it asks ObjectRequestBankMatcher which
 * request the line quotes and writes the match: confirmed when exactly one
 * request is quoted for the amount still open, a candidate for the bookkeeper
 * otherwise. On the `confirm` transition of a `payment-request` match, by
 * either route, it settles the request through ObjectRequestBankSettlement.
 * A new line that brought in a booked provider payout is matched to that
 * payout first, through PayoutBankMatcher, and is then no request's
 * (receivables-provider-payouts D4, REQ-RPPO-004).
 * Fail-soft: a problem is logged and never undoes the bank line.
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ManualMatchServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankMatcherInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankSettlementInterface;
use OCA\Shillinq\Service\Payout\PayoutBankMatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * New bank line to match; confirmed payment-request match to settlement.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
 */
class BankLineObjectRequestListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ObjectRequestBankMatcherInterface $matcher Decides what a line is to the requests.
	 * @param ManualMatchServiceInterface $manualMatch Writes the match.
	 * @param ObjectRequestBankSettlementInterface $settlement Settles a request on a confirmed match.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug.
	 * @param LoggerInterface $logger Fail-soft log.
	 * @param PayoutBankMatcher $payouts Matches a line to the booked provider payout it brought in.
	 */
	public function __construct(
		private readonly ObjectRequestBankMatcherInterface $matcher,
		private readonly ManualMatchServiceInterface $manualMatch,
		private readonly ObjectRequestBankSettlementInterface $settlement,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly PayoutBankMatcher $payouts,
	) {
	}//end __construct()

	/**
	 * Route the event.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectCreatedEvent) {
				$this->onLine(entity: $event->getObject());
				return;
			}

			if ($event instanceof ObjectTransitionedEvent && $event->getAction() === 'confirm') {
				$this->onConfirm(entity: $event->getObject());
			}
		} catch (Throwable $e) {
			$this->logger->warning('BankLineObjectRequestListener: object request not matched or settled', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Match a new bank line to the request it quotes.
	 *
	 * @param ObjectEntity|null $entity The saved line.
	 *
	 * @return void
	 */
	private function onLine(?ObjectEntity $entity): void {
		if ($entity === null || $this->schemaResolver->schemaSlug(entity: $entity) !== 'BankStatementLine') {
			return;
		}

		$line = ($entity->getObject() ?? []);
		$line['id'] = (string)($entity->getUuid() ?? ($line['id'] ?? ''));
		if ((string)($line['status'] ?? 'unmatched') !== 'unmatched') {
			return;
		}

		// A provider payout's line is reconciled to the payout and is no request's (REQ-RPPO-004).
		if ($this->payouts->reconcile(line: $line) !== null) {
			return;
		}

		$found = $this->matcher->match(line: $line);
		if ($found['decision'] === ObjectRequestBankMatcherInterface::DECISION_CONFIRM) {
			$this->manualMatch->matchPaymentRequest(line: $line, request: $found['requests'][0], actor: ObjectRequestBankSettlementInterface::ACTOR);
			return;
		}

		if ($found['decision'] === ObjectRequestBankMatcherInterface::DECISION_CANDIDATE) {
			foreach ($found['requests'] as $request) {
				$this->manualMatch->proposePaymentRequest(line: $line, request: $request);
			}
		}
	}//end onLine()

	/**
	 * Settle the request of a confirmed payment-request match.
	 *
	 * @param ObjectEntity|null $entity The confirmed match.
	 *
	 * @return void
	 */
	private function onConfirm(?ObjectEntity $entity): void {
		if ($entity === null || $this->schemaResolver->schemaSlug(entity: $entity) !== 'ReconciliationMatch') {
			return;
		}

		$match = ($entity->getObject() ?? []);
		if ((string)($match['matchType'] ?? '') !== 'payment-request') {
			return;
		}

		$this->settlement->settle(match: $match);
	}//end onConfirm()
}//end class
