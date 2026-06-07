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
final class LoggingTimelineRetryQueue implements TimelineRetryQueue
{
    /**
     * Construct the logging fallback queue.
     *
     * @param LoggerInterface $logger PSR logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {

    }//end __construct()

    /**
     * Record the deferral at WARNING level so operators can spot it.
     *
     * @param TimelineEventDto $event Event the synchronous publish failed for.
     *
     * @return void
     */
    public function enqueue(TimelineEventDto $event): void
    {
        $this->logger->warning(
            'pipelinq timeline publish deferred (no persistent queue yet)',
            [
                'app'        => Application::APP_ID,
                'type'       => $event->type(),
                'externalId' => $event->externalId(),
                'contactId'  => $event->contactId(),
            ]
        );

    }//end enqueue()
}//end class
