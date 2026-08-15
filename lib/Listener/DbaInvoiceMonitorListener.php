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

use OCA\Shillinq\Service\DBAVbarMonitorService;
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
	 * @param DBAVbarMonitorService $vbarMonitor VBAR monitoring service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DBAVbarMonitorService $vbarMonitor,
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

		$administrationId = (string)($payload['administrationId'] ?? '');
		$invoiceId = (string)($payload['@self']['id'] ?? ($payload['id'] ?? ''));
		$amountCents = (int)($payload['bedragCents'] ?? ($payload['totalAmountCents'] ?? 0));
		$hours = (float)($payload['hours'] ?? ($payload['hoursBilled'] ?? 0.0));

		try {
			$result = $this->vbarMonitor->assess(
				amountCents: $amountCents,
				hours: $hours,
				administrationId: $administrationId,
			);
			if ($result['result'] !== DBAVbarMonitorService::RESULT_OK
				&& $invoiceId !== ''
			) {
				$this->vbarMonitor->emitFlag(
					assignmentId: $assignmentId,
					administrationId: $administrationId,
					invoiceId: $invoiceId,
					hourlyRateCents: (int)($result['uurtariefCents'] ?? 0),
					vbarGrensCents: (int)($result['vbarGrensCents'] ?? 0),
				);
			}
		} catch (Throwable $e) {
			// Non-blocking: log and continue. The AP/AR factuur commit MUST NOT
			// be undone by a DBA monitoring hiccup.
			$this->logger->warning(
				'DbaInvoiceMonitorListener: VBAR assess/emit failed (non-blocking).',
				['assignmentId' => $assignmentId, 'invoiceId' => $invoiceId, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

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
			if (method_exists($event, 'getObject') === true) {
				$obj = $event->getObject();
				if (is_array($obj) === true) {
					/*
					 * @var array<string,mixed> $obj
					 */

					return $obj;
				}

				if (is_object($obj) === true && method_exists($obj, 'getObject') === true) {
					$data = $obj->getObject();
					if (is_array($data) === true) {
						/*
						 * @var array<string,mixed> $data
						 */

						return $data;
					}
				}
			}//end if

			if (method_exists($event, 'getData') === true) {
				$data = $event->getData();
				if (is_array($data) === true) {
					/*
					 * @var array<string,mixed> $data
					 */

					return $data;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('DbaInvoiceMonitorListener: extract failed', ['exception' => $e->getMessage()]);
		}//end try

		return null;
	}//end extractInvoice()
}//end class
