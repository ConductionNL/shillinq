<?php

/**
 * Default TimelineRetryQueue binding — logs the deferral.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Used until slice 09 ships the persistent background-job-backed queue.
 * Each {@see self::enqueue()} call writes a WARNING log line carrying the
 * event type + booking id so operators can spot failed publishes during
 * the time window between slice 07 and slice 09. No secrets reach the
 * log (the event payload itself carries no token; see ADR-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use OCA\Shillinq\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Logs the deferred publish; slice 09 swaps this binding for a real queue.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class LoggingTimelineRetryQueue implements TimelineRetryQueue {

	/**
	 * In-memory counter of deferrals seen so far in this process.
	 *
	 * Used as a best-effort "dead-letter count" gauge until slice 09 ships
	 * the persistent queue. We deliberately do not write this counter to
	 * IAppConfig — that would couple a transient observability gauge to
	 * persistent state. The metrics service holds the ICache-backed
	 * cross-request value.
	 *
	 * @var integer
	 */
	private int $deferrals = 0;

	/**
	 * Construct the logging fallback queue.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 * @param CustomerBridgeMetricsService|null $metrics Optional metrics aggregator (slice 11).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?CustomerBridgeMetricsService $metrics = null,
	) {

	}//end __construct()

	/**
	 * Record the deferral at WARNING level so operators can spot it.
	 *
	 * @param TimelineEventDto $event Event the synchronous publish failed for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function enqueue(TimelineEventDto $event): void {
		$this->deferrals += 1;

		$this->logger->warning(
			'pipelinq timeline publish deferred (no persistent queue yet)',
			[
				'app' => Application::APP_ID,
				'type' => $event->type(),
				'externalId' => $event->externalId(),
				'contactId' => $event->contactId(),
			]
		);

		// Slice 09 will own the real dead-letter queue size; until then,
		// surface the per-process count so an admin dashboard at least
		// sees "this many deferrals happened during the current
		// collection window".
		$this->metrics?->recordDeadLetterCount(count: $this->deferrals);

	}//end enqueue()
}//end class
