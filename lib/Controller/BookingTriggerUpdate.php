<?php

/**
 * Booking Trigger Update
 *
 * One run of PUT /api/bookings/{id}/notifications over its update entries.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The object service and booking of one trigger update, the slug of the
 * entry being applied and the triggers applied so far.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 */
final class BookingTriggerUpdate {
	/**
	 * The slug of the update entry being applied.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * The triggers persisted so far.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $applied = [];

	/**
	 * Start the run.
	 *
	 * @param object $service OpenRegister's object service.
	 * @param string $bookingId The booking whose triggers are updated.
	 */
	public function __construct(
		public readonly object $service,
		public readonly string $bookingId,
	) {
	}//end __construct()
}//end class
