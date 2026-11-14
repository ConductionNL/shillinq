<?php

/**
 * What a booking timeline event reads from an Appointment.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * The Appointment schema match, the linked pipelinq contact, the booking id
 * and the fixed timeline metadata shared by
 * {@see BookingCreatedTimelinePublishListener} and
 * {@see BookingLifecycleTransitionListener}.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class BookingTimelineFields {

	/**
	 * Whether the supplied schema string identifies the Appointment schema.
	 *
	 * Schema strings may be plain slugs or fully-qualified register/schema
	 * pairs depending on OR config, so the comparison is permissive.
	 *
	 * @param string $schema Schema identifier.
	 *
	 * @return bool TRUE when the schema is "Appointment".
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function isAppointmentSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'appointment' || str_ends_with($normalised, '/appointment'));

	}//end isAppointmentSchema()

	/**
	 * The linked pipelinq Contact id ('' when the booking is not linked).
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function contactId(array $appointment): string {
		return trim((string)($appointment['pipelinqContactId'] ?? ''));

	}//end contactId()

	/**
	 * The booking id used as the timeline externalId ('' when missing).
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function externalId(array $appointment): string {
		return trim((string)($appointment['appointmentId'] ?? ''));

	}//end externalId()

	/**
	 * The fixed timeline metadata (giant Risk 3): only the scalar fields the
	 * pipelinq timeline consumer is documented to render, absent fields as
	 * empty strings (NOT dropped) so the contract stays stable.
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function metadata(array $appointment): array {
		return [
			'bookingNumber' => $this->externalId(appointment: $appointment),
			'service' => (string)($appointment['serviceId'] ?? ''),
			'startTime' => (string)($appointment['startTime'] ?? ''),
			'endTime' => (string)($appointment['endTime'] ?? ''),
			'resourceId' => (string)($appointment['resourceId'] ?? ''),
			'status' => (string)($appointment['status'] ?? ''),
		];

	}//end metadata()
}//end class
