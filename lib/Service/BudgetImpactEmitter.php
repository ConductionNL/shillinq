<?php

/**
 * Budget Impact Emitter Service.
 *
 * REQ-007 — on activation of a `bron: tenderned` Commitment, publish an
 * `obligation.activated` CloudEvent so launchpad's budget-utilisation widget
 * can reflect the new committed expense within 60 seconds.
 *
 * The emitter is a thin wrapper over the Nextcloud `IEventDispatcher`:
 * the actual cross-app transport is the OR event bus (consumed by the
 * launchpad listener, which lives in its own app and is NOT a shillinq
 * concern). The emitter only:
 *
 *  1. Shapes a deterministic CloudEvent payload (contractWaarde, period,
 *     kostenplaats, dossier URL, idempotency key on bronReferentie).
 *  2. Dispatches a generic `GenericEvent` carrying the payload so the
 *     cross-app subscriber (or test harness) can pick it up without a
 *     shillinq-owned event class. Wrapping in a typed class is reserved
 *     for the cross-app contract reconciliation (deferred — design D4).
 *
 * The same emitter is reused by the OrderFulfilmentTransitionListener
 * for the `milestone.completed` event (Task 5.3), so the spec's three
 * REQ-007/REQ-002/REQ-005 emission points share one tested kernel.
 *
 * No bespoke HTTP transport; the CloudEvent leaves shillinq through the
 * shared NC event-dispatcher and the openconnector outbound source
 * (live-instance wiring deferred per Task 0.2).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CloudEvent emitter for the budget-impact + milestone-completed cross-app surface.
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 */
class BudgetImpactEmitter {

	/**
	 * Event name for an activated TenderNed-sourced obligation (REQ-007).
	 *
	 * @var string
	 */
	public const EVENT_OBLIGATION_ACTIVATED = 'shillinq.obligation.activated';

	/**
	 * Event name for a completed milestone (REQ-005 audit-trail emission).
	 *
	 * @var string
	 */
	public const EVENT_MILESTONE_COMPLETED = 'shillinq.milestone.completed';

	/**
	 * Construct the emitter.
	 *
	 * @param IEventDispatcher $dispatcher NC event dispatcher (cross-app transport).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Emit the `obligation.activated` CloudEvent (REQ-007).
	 *
	 * Idempotency: subscribers MUST treat `bronReferentie` + `eventName`
	 * as the dedup key so retries from the listener (after a network
	 * failure on the openconnector outbound source) do not double-count
	 * the committed expense.
	 *
	 * @param array<string, mixed> $commitment Activated Commitment payload.
	 * @param array<string, mixed> $source Source TenderNedProcurement payload (dossier URL).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md#req-007
	 */
	public function emitActivated(array $commitment, array $source = []): void {
		$payload = [
			'eventName' => self::EVENT_OBLIGATION_ACTIVATED,
			'sourceReference' => (string)($commitment['sourceReference'] ?? ''),
			'contractValue' => (float)($commitment['amount'] ?? 0),
			'costCentre' => (string)($commitment['costCentre'] ?? ''),
			'termStart' => (string)($commitment['termStart'] ?? ''),
			'termEnd' => (string)($commitment['termEnd'] ?? ''),
			'tenderNedUrl' => (string)($source['tenderNedUrl'] ?? ''),
			'administrationId' => (string)($commitment['administrationId'] ?? ''),
			'emittedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
		];

		$this->dispatch(eventName: self::EVENT_OBLIGATION_ACTIVATED, payload: $payload);

	}//end emitActivated()

	/**
	 * Emit the `milestone.completed` CloudEvent (Task 5.3 / REQ-005).
	 *
	 * Carries the OrderFulfilment identifier, the linked obligation,
	 * the approval marker, and the bewijsstuk count so downstream
	 * subscribers (audit-trail consumer + budget-utilisation widget) can
	 * reconcile without re-fetching the OR record.
	 *
	 * @param array<string, mixed> $oplevering Completed OrderFulfilment payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md#req-005
	 */
	public function emitMilestoneCompleted(array $oplevering): void {
		$supportingDocuments = ($oplevering['supportingDocuments'] ?? []);
		if (is_array($supportingDocuments) === false) {
			$supportingDocuments = [];
		}

		$payload = [
			'eventName' => self::EVENT_MILESTONE_COMPLETED,
			'commitmentId' => (string)($oplevering['commitmentId'] ?? ''),
			'milestoneId' => (string)($oplevering['milestoneId'] ?? ''),
			'deliveryType' => (string)($oplevering['deliveryType'] ?? ''),
			'deliveryDate' => (string)($oplevering['deliveryDate'] ?? ''),
			'approved' => (bool)($oplevering['approved'] ?? false),
			'bewijsstukCount' => count($supportingDocuments),
			'administrationId' => (string)($oplevering['administrationId'] ?? ''),
			'emittedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
		];

		$this->dispatch(eventName: self::EVENT_MILESTONE_COMPLETED, payload: $payload);

	}//end emitMilestoneCompleted()

	/**
	 * Dispatch the payload as a GenericEvent.
	 *
	 * Fail-soft: any dispatcher error is logged but never bubbled up to
	 * the originating OR write path (the OR record is the source of
	 * truth; the cross-app notification is a derived view).
	 *
	 * @param string $eventName CloudEvent name.
	 * @param array<string, mixed> $payload Event payload.
	 *
	 * @return void
	 */
	private function dispatch(string $eventName, array $payload): void {
		try {
			$event = new GenericEvent(null, $payload);
			$this->dispatcher->dispatch($eventName, $event);
		} catch (Throwable $e) {
			$this->logger->info(
				'BudgetImpactEmitter: dispatch failed — fail-soft',
				['eventName' => $eventName, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end dispatch()
}//end class
