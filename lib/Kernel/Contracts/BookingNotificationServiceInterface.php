<?php

/**
 * Contract of BookingNotificationService
 *
 * The public surface of BookingNotificationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BookingNotificationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BookingNotificationServiceInterface {

	/**
	 * Evaluate all active triggers for the given booking event type and dispatch
	 * notifications to matching recipients.
	 *
	 * Orchestrates the full pipeline: load triggers → evaluate conditions →
	 * check rate-limit and opt-out → render template → dispatch → audit.
	 *
	 * @param string $eventType Booking event: created|changed|cancelled|reminder.
	 * @param array<mixed> $booking Full booking object payload (see REQ-BNT-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function evaluateEventTrigger(string $eventType, array $booking): void;
}//end interface
