<?php

/**
 * Outcome of a `publishTimelineEvent()` call on the pipelinq adapter.
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Slice 07 reported success / failure as a plain `bool` — sufficient
 * for the booking-created listener because every non-success was
 * routed straight to the async retry queue. Slice 08 adds lifecycle
 * transitions (confirmed / cancelled / completed) **and** the
 * giant's auth-error decision: a 401 from pipelinq is *permanent*
 * (revoked / expired token) and MUST NOT be retried.
 *
 * The listener therefore needs to distinguish three terminal outcomes:
 *
 *   - {@see self::Success}       — published; do nothing.
 *   - {@see self::AuthRejected}  — 401; do NOT retry, notify admin.
 *   - {@see self::Transient}     — open breaker / 5xx / 408 / 429 /
 *                                  network error / non-401 4xx; hand
 *                                  off to the retry queue (slice 09).
 *
 * Keeping this as a value object (PHP 8.1 enum) keeps the publish
 * surface area small and the listener decision tree readable while
 * still preserving the slice-07 `bool` return type via the legacy
 * {@see PipelinqContactAdapter::publishTimelineEvent()} wrapper.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Terminal outcome of a single pipelinq timeline publish.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
enum TimelinePublishOutcome: string {
	// The publish completed with a 2xx response.
	case Success = 'success';

	// The endpoint returned 401 Unauthorized — the configured pipelinq
	// token is invalid / revoked. The publish MUST NOT be retried; the
	// adapter logs ERROR, the listener fires an admin notification.
	case AuthRejected = 'auth_rejected';

	// The publish failed for a transient reason (open breaker, retry
	// budget exhausted, transport error, non-401 client error). The
	// listener hands the event off to the async retry queue (slice 09).
	case Transient = 'transient';

	/**
	 * TRUE for the only outcome that should be treated as published.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this === self::Success;
	}//end isSuccess()
}//end enum
