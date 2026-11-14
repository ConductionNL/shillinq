<?php

/**
 * DBA AP/AR factuur monitoring listener.
 *
 * Optional hook (T31 + T32) that listens to ARInvoice / APInvoice creation
 * events from the bookkeeping-accounts-receivable-core and
 * bookkeeping-accounts-payable-core capabilities. When an outbound factuur
 * is registered with a DBA-opdrachtId in its metadata, the listener feeds:
 *  - factuurfrequentie-monitoring (REQ-DBA-004, T31) by appending the factuur
 *    to the opdracht's frequency window;
 *  - VBAR uurtarief-toets (REQ-DBA-016, T32) by computing the effective rate
 *    and emitting VBAR_GRENS_ONDERSCHREDEN when below threshold.
 *
 * The listener is **non-blocking**: if the AP/AR capability is not present,
 * the listener is simply never invoked. If the OR ObjectService is missing
 * (rare), all work is logged and skipped.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Kernel\Contracts\DBAVbarMonitorServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Optional non-blocking hook from AP/AR into DBA monitoring.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DbaInvoiceMonitorListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param DBAVbarMonitorServiceInterface $vbarMonitor VBAR monitoring service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DBAVbarMonitorServiceInterface $vbarMonitor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an AP/AR factuur-created event (REQ-DBA-004 + REQ-DBA-016).
	 *
	 * @param Event $event The OR ObjectCreatedEvent (typed loosely so the
	 *                     listener compiles without an OR autoloader available).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function handle(Event $event): void {
		$payload = $this->extractInvoice(event: $event);
		if ($payload === null) {
			return;
		}

		$assignmentId = (string)($payload['dbaOpdrachtId'] ?? '');
		if ($assignmentId === '') {
			// Not tied to a DBA opdracht — no work to do.
			return;
		}

		$facts = new DbaInvoiceFacts(
			assignmentId: $assignmentId,
			administrationId: (string)($payload['administrationId'] ?? ''),
			invoiceId: (string)($payload['@self']['id'] ?? ($payload['id'] ?? '')),
			amountCents: (int)($payload['bedragCents'] ?? ($payload['totalAmountCents'] ?? 0)),
			hours: (float)($payload['hours'] ?? ($payload['hoursBilled'] ?? 0.0))
		);

		try {
			$this->assessAndFlag(facts: $facts);
		} catch (Throwable $e) {
			// Non-blocking: log and continue. The AP/AR factuur commit MUST NOT
			// be undone by a DBA monitoring hiccup.
			$this->logger->warning(
				'DbaInvoiceMonitorListener: VBAR assess/emit failed (non-blocking).',
				['assignmentId' => $facts->assignmentId, 'invoiceId' => $facts->invoiceId, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Assess the invoice against the VBAR limit and flag a known invoice over it.
	 *
	 * @param DbaInvoiceFacts $facts The invoice facts.
	 *
	 * @return void
	 */
	private function assessAndFlag(DbaInvoiceFacts $facts): void {
		$result = $this->vbarMonitor->assess(
			amountCents: $facts->amountCents,
			hours: $facts->hours,
			administrationId: $facts->administrationId,
		);
		if ($result['result'] !== DBAVbarMonitorServiceInterface::RESULT_OK
			&& $facts->invoiceId !== ''
		) {
			$this->vbarMonitor->emitFlag(
				assignmentId: $facts->assignmentId,
				administrationId: $facts->administrationId,
				invoiceId: $facts->invoiceId,
				hourlyRateCents: (int)($result['uurtariefCents'] ?? 0),
				vbarGrensCents: (int)($result['vbarGrensCents'] ?? 0),
			);
		}

	}//end assessAndFlag()

	/**
	 * Extract an invoice payload from a heterogeneous OR event.
	 *
	 * Tries `$event->getObject()`, `$event->getObject()->getObject()`, and
	 * `$event->getData()` so we are tolerant of multiple OR event versions.
	 *
	 * @param Event $event Any OR event.
	 *
	 * @return array<string,mixed>|null Invoice payload, or null when not extractable.
	 */
	private function extractInvoice(Event $event): ?array {
		try {
			return ($this->objectPayload(event: $event) ?? $this->dataPayload(event: $event));
		} catch (Throwable $e) {
			$this->logger->debug('DbaInvoiceMonitorListener: extract failed', ['exception' => $e->getMessage()]);
		}

		return null;
	}//end extractInvoice()

	/**
	 * The payload from `getObject()`, either an array or an entity's object.
	 *
	 * @param Event $event Any OR event.
	 *
	 * @return array<string,mixed>|null The payload, or null when not extractable this way.
	 */
	private function objectPayload(Event $event): array|null {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$obj = $event->getObject();
		if (is_array($obj) === true) {
			return $obj;
		}

		if (is_object($obj) === true && method_exists($obj, 'getObject') === true) {
			$data = $obj->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;

	}//end objectPayload()

	/**
	 * The payload from `getData()`.
	 *
	 * @param Event $event Any OR event.
	 *
	 * @return array<string,mixed>|null The payload, or null when not extractable this way.
	 */
	private function dataPayload(Event $event): array|null {
		if (method_exists($event, 'getData') === true) {
			$data = $event->getData();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;

	}//end dataPayload()
}//end class
