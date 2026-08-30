<?php

/**
 * Admin-notifier port for the pipelinq customer bridge.
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * On a 401 from pipelinq the giant's auth-error decision requires us to
 * alert an administrator: the token is permanently invalid and a human
 * needs to rotate it. The notification path is abstracted behind this
 * port so the listener stays test-friendly and so the slice-08 default
 * binding can stay log-only (the canonical NC notification surface lives
 * in a later slice that owns the dispatching policy + the cooldown).
 *
 * Security (ADR-005):
 *   - Implementations MUST NOT include the configured token in any
 *     subject, parameter, or log line. The slice-08 design.md is
 *     explicit: the notification references the *config location*, not
 *     the secret value.
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
 * Port that surfaces a "pipelinq token invalid" alert to administrators.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
interface PipelinqAdminNotifier {
	/**
	 * Notify an administrator that the configured pipelinq token was
	 * rejected by the remote (401 Unauthorized).
	 *
	 * Implementations MUST be cheap and non-throwing — the caller invokes
	 * this from a listener whose primary side-effect (the booking
	 * transition commit) has already happened.
	 *
	 * @param TimelineEventDto $event The event whose publish was rejected.
	 *
	 * @return void
	 */
	public function notifyAuthFailure(TimelineEventDto $event): void;
}//end interface
