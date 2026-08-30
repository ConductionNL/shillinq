<?php

/**
 * Async retry queue port for failed timeline publishes.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * When the synchronous {@see PipelinqContactAdapter::publishTimelineEvent()}
 * call fails (open breaker, retry budget exhausted, transport error)
 * the slice-07 booking-created listener hands the event off through this
 * port so the booking commit is not blocked. The default
 * {@see LoggingTimelineRetryQueue} implementation simply logs the
 * deferral — slice 09 replaces the binding with a real persistent
 * queue + background job. Keeping this as an interface lets slice 09
 * land without touching slice 07's listener wiring.
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

/**
 * Port that consumes timeline events that failed synchronous publish.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
interface TimelineRetryQueue {
	/**
	 * Hand an event off for async retry.
	 *
	 * Implementations MUST be cheap and non-throwing — the caller invokes
	 * this from a listener whose primary side-effect (the booking commit)
	 * has already happened; raising here would mask the original publish
	 * failure with a queueing failure.
	 *
	 * @param TimelineEventDto $event Event to retry.
	 *
	 * @return void
	 */
	public function enqueue(TimelineEventDto $event): void;
}//end interface
