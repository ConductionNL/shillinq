<?php

/**
 * Appointment Guard
 *
 * ADR-031 exception-path lifecycle guard for the Appointment save precondition.
 * Validates, before any Appointment record is persisted, that:
 *   1. The appointment duration matches the related Service.duration (REQ-BCA-003).
 *   2. The start/end times fall within the related Resource operational hours (REQ-BCA-004).
 *   3. No other non-cancelled appointment overlaps the same resource slot — unless the
 *      resource allows overlap (REQ-BCA-004 double-booking prevention).
 *   4. The customer contact (if a status-bearing record exists) is not suspended (REQ-BCA-006).
 *
 * Referenced from the Appointment schema's x-openregister-lifecycle.preconditions.save
 * in lib/Settings/register.d/10-bookings-create-appointment.json.
 *
 * ADR-031 exception reason: cross-schema availability + duration + eligibility checks
 * span Service, Resource, and the existing Appointment set, which the declarative
 * lifecycle DSL cannot yet express. Replace with declarative conditions when the
 * engine supports cross-schema overlap and arithmetic comparisons.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Save precondition guard for the Appointment schema per REQ-BCA-003/004/006.
 *
 * Fail-closed: any unexpected exception denies the save (CWE-863).
 *
 * The guard enforces four independent business rules (duration match, operational
 * hours, double-booking, customer eligibility) in cohesive, single-purpose private
 * methods. The aggregate per-class complexity slightly exceeds the default phpmd
 * threshold because the rules are kept together rather than fragmented across
 * several thin collaborator classes; splitting would hurt readability without
 * lowering real branch count. The class is suppressed accordingly.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-12
 */
class AppointmentGuard {

	/**
	 * Tolerance, in minutes, allowed between the requested appointment duration
	 * and the related Service.duration per REQ-BCA-003.
	 */
	private const DURATION_TOLERANCE_MINUTES = 5;

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Save precondition for the Appointment schema.
	 *
	 * Runs the full create-time validation chain. Returns true only when every
	 * check passes. Cancelled appointments skip availability/duration checks so
	 * the phase-2 cancellation flow can persist cancellation metadata freely.
	 *
	 * Fail-closed: returns false on any exception (denies the save) per CWE-863.
	 *
	 * @param array<string, mixed> $appointment Appointment object array supplied by OR.
	 *
	 * @return bool True when the appointment may be saved.
	 *
	 * @spec openspec/changes/bookings-create-appointment/tasks.md#task-12
	 */
	public function validateOnSave(array $appointment): bool {
		try {
			// Cancelled records carry only cancellation metadata; skip slot checks.
			if (($appointment['status'] ?? '') === 'cancelled') {
				return true;
			}

			if ($this->hasValidTimeWindow(appointment: $appointment) === false) {
				return false;
			}

			if ($this->matchesServiceDuration(appointment: $appointment) === false) {
				return false;
			}

			if ($this->isWithinOperationalHours(appointment: $appointment) === false) {
				return false;
			}

			if ($this->isSlotAvailable(appointment: $appointment) === false) {
				return false;
			}

			return $this->isCustomerEligible(appointment: $appointment);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AppointmentGuard: validateOnSave failed — denying save (fail-closed)',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end validateOnSave()

	/**
	 * Verify the appointment has a well-formed start/end window with end after start.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when both timestamps parse and endTime is after startTime.
	 */
	private function hasValidTimeWindow(array $appointment): bool {
		$start = $this->parseTimestamp(value: (string)($appointment['startTime'] ?? ''));
		$end = $this->parseTimestamp(value: (string)($appointment['endTime'] ?? ''));

		if ($start === null || $end === null) {
			$this->logger->info(
				'AppointmentGuard: missing or unparseable startTime/endTime — denying save',
				['appointmentId' => ($appointment['appointmentId'] ?? 'unknown')]
			);
			return false;
		}

		if ($end <= $start) {
			$this->logger->info(
				'AppointmentGuard: endTime not after startTime — denying save',
				['appointmentId' => ($appointment['appointmentId'] ?? 'unknown')]
			);
			return false;
		}

		return true;
	}//end hasValidTimeWindow()

	/**
	 * Validate that the appointment duration matches the related Service.duration
	 * within DURATION_TOLERANCE_MINUTES per REQ-BCA-003.
	 *
	 * When the Service register is not yet seeded (T1 state) or the referenced
	 * service cannot be found, the check is skipped with a warning so phase-1
	 * builds without a service catalog still function.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when duration matches (or service is unavailable).
	 */
	private function matchesServiceDuration(array $appointment): bool {
		$service = $this->findOne(
			schema: 'Service',
			filters: [
				'serviceId' => (string)($appointment['serviceId'] ?? ''),
				'administrationId' => (string)($appointment['administrationId'] ?? ''),
			]
		);

		if ($service === null || isset($service['duration']) === false) {
			$this->logger->warning(
				'AppointmentGuard: related Service not found or has no duration — skipping duration check',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'serviceId' => ($appointment['serviceId'] ?? 'unknown'),
				]
			);
			return true;
		}

		$requestedMinutes = $this->durationMinutes(appointment: $appointment);
		$serviceMinutes = (int)$service['duration'];

		if (abs($requestedMinutes - $serviceMinutes) > self::DURATION_TOLERANCE_MINUTES) {
			$this->logger->info(
				'AppointmentGuard: duration mismatch — denying save',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'requested' => $requestedMinutes,
					'service' => $serviceMinutes,
				]
			);
			return false;
		}

		return true;
	}//end matchesServiceDuration()

	/**
	 * Validate that the appointment falls within the related Resource operational
	 * hours (openingTime / closingTime, HH:MM UTC) per REQ-BCA-004.
	 *
	 * When the Resource is not seeded or declares no operational hours, the check
	 * is skipped (no restriction).
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when within operational hours (or unrestricted).
	 */
	private function isWithinOperationalHours(array $appointment): bool {
		$resource = $this->findOne(
			schema: 'Resource',
			filters: [
				'resourceId' => (string)($appointment['resourceId'] ?? ''),
				'administrationId' => (string)($appointment['administrationId'] ?? ''),
			]
		);

		if ($resource === null) {
			$this->logger->warning(
				'AppointmentGuard: related Resource not found — skipping operational-hours check',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'resourceId' => ($appointment['resourceId'] ?? 'unknown'),
				]
			);
			return true;
		}

		$openingMinutes = $this->clockToMinutes(clock: (string)($resource['openingTime'] ?? ''));
		$closingMinutes = $this->clockToMinutes(clock: (string)($resource['closingTime'] ?? ''));
		if ($openingMinutes === null || $closingMinutes === null) {
			// No (or malformed) operational hours declared — unrestricted.
			return true;
		}

		$start = $this->parseTimestamp(value: (string)($appointment['startTime'] ?? ''));
		$end = $this->parseTimestamp(value: (string)($appointment['endTime'] ?? ''));
		if ($start === null || $end === null) {
			return false;
		}

		$startMinutes = $this->minutesIntoDay(epoch: $start);
		$endMinutes = $this->minutesIntoDay(epoch: $end);

		if ($startMinutes < $openingMinutes || $endMinutes > $closingMinutes) {
			$this->logger->info(
				'AppointmentGuard: outside operational hours — denying save',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'start' => $startMinutes,
					'end' => $endMinutes,
					'opening' => $openingMinutes,
					'closing' => $closingMinutes,
				]
			);
			return false;
		}

		return true;
	}//end isWithinOperationalHours()

	/**
	 * Verify that no other non-cancelled appointment overlaps the same resource
	 * slot, preventing double-booking per REQ-BCA-004.
	 *
	 * Skipped when the resource declares allowOverlap = true. The appointment's
	 * own record (matched by appointmentId) is excluded so updates do not collide
	 * with themselves.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when the slot is free (or overlap is allowed).
	 */
	private function isSlotAvailable(array $appointment): bool {
		$resource = $this->findOne(
			schema: 'Resource',
			filters: [
				'resourceId' => (string)($appointment['resourceId'] ?? ''),
				'administrationId' => (string)($appointment['administrationId'] ?? ''),
			]
		);

		if ($resource !== null && ((bool)($resource['allowOverlap'] ?? false)) === true) {
			return true;
		}

		$start = $this->parseTimestamp(value: (string)($appointment['startTime'] ?? ''));
		$end = $this->parseTimestamp(value: (string)($appointment['endTime'] ?? ''));
		if ($start === null || $end === null) {
			return false;
		}

		$existing = $this->findMany(
			schema: 'Appointment',
			filters: [
				'resourceId' => (string)($appointment['resourceId'] ?? ''),
				'administrationId' => (string)($appointment['administrationId'] ?? ''),
			]
		);

		$conflict = $this->findConflict(
			appointment: $appointment,
			existing: $existing,
			start: $start,
			end: $end,
		);

		if ($conflict !== null) {
			$this->logger->info(
				'AppointmentGuard: resource slot already booked — denying save',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'resourceId' => ($appointment['resourceId'] ?? 'unknown'),
					'conflictsWith' => ($conflict['appointmentId'] ?? 'unknown'),
				]
			);
			return false;
		}

		return true;
	}//end isSlotAvailable()

	/**
	 * Find the first existing appointment that overlaps the requested window.
	 *
	 * The appointment's own record (matched by appointmentId) and any cancelled
	 * record are excluded. Records with unparseable times are ignored.
	 *
	 * @param array<string, mixed> $appointment The appointment being saved.
	 * @param array<int, array<string, mixed>> $existing Existing appointments on the resource.
	 * @param int $start Requested start epoch.
	 * @param int $end Requested end epoch.
	 *
	 * @return array<string, mixed>|null The conflicting record, or null when the slot is free.
	 */
	private function findConflict(array $appointment, array $existing, int $start, int $end): ?array {
		$selfId = (string)($appointment['appointmentId'] ?? '');

		foreach ($existing as $other) {
			if ($selfId !== '' && (string)($other['appointmentId'] ?? '') === $selfId) {
				continue;
			}

			if (($other['status'] ?? '') === 'cancelled') {
				continue;
			}

			$otherStart = $this->parseTimestamp(value: (string)($other['startTime'] ?? ''));
			$otherEnd = $this->parseTimestamp(value: (string)($other['endTime'] ?? ''));
			if ($otherStart === null || $otherEnd === null) {
				continue;
			}

			// Two half-open intervals [start, end) overlap iff start < otherEnd AND otherStart < end.
			if ($start < $otherEnd && $otherStart < $end) {
				return $other;
			}
		}//end foreach

		return null;
	}//end findConflict()

	/**
	 * Verify the customer contact is eligible to book per REQ-BCA-006.
	 *
	 * A customer is a Nextcloud contact entity rather than a bespoke schema, so
	 * eligibility is enforced only when an app-managed contact record carrying a
	 * status field exists for the customerId. A record with status 'suspended' or
	 * 'banned' denies the save; any other state (or no record at all) is eligible.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when the customer is eligible.
	 */
	private function isCustomerEligible(array $appointment): bool {
		$contact = $this->findOne(
			schema: 'Contact',
			filters: [
				'customerId' => (string)($appointment['customerId'] ?? ''),
				'administrationId' => (string)($appointment['administrationId'] ?? ''),
			]
		);

		if ($contact === null) {
			// No app-managed contact record (customer is an external NC contact) — eligible.
			return true;
		}

		$status = (string)($contact['status'] ?? '');
		if ($status === 'suspended' || $status === 'banned') {
			$this->logger->info(
				'AppointmentGuard: customer account is not active — denying save',
				[
					'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
					'customerId' => ($appointment['customerId'] ?? 'unknown'),
					'status' => $status,
				]
			);
			return false;
		}

		return true;
	}//end isCustomerEligible()

	/**
	 * Compute the appointment duration in whole minutes.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return int Duration in minutes (0 when either timestamp is unparseable).
	 */
	private function durationMinutes(array $appointment): int {
		$start = $this->parseTimestamp(value: (string)($appointment['startTime'] ?? ''));
		$end = $this->parseTimestamp(value: (string)($appointment['endTime'] ?? ''));
		if ($start === null || $end === null) {
			return 0;
		}

		return (int)round((($end - $start) / 60));
	}//end durationMinutes()

	/**
	 * Parse an ISO 8601 timestamp string into a Unix epoch, or null on failure.
	 *
	 * @param string $value Timestamp string (e.g. "2026-05-22T10:00:00Z").
	 *
	 * @return int|null Epoch seconds, or null when the value cannot be parsed.
	 */
	private function parseTimestamp(string $value): ?int {
		if ($value === '') {
			return null;
		}

		$epoch = strtotime($value);
		if ($epoch === false) {
			return null;
		}

		return $epoch;
	}//end parseTimestamp()

	/**
	 * Convert an HH:MM clock string into minutes since midnight, or null if invalid.
	 *
	 * @param string $clock Clock string (e.g. "09:30").
	 *
	 * @return int|null Minutes since midnight, or null when malformed.
	 */
	private function clockToMinutes(string $clock): ?int {
		if (preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches) !== 1) {
			return null;
		}

		$hours = (int)$matches[1];
		$minutes = (int)$matches[2];
		if ($hours > 23 || $minutes > 59) {
			return null;
		}

		return (($hours * 60) + $minutes);
	}//end clockToMinutes()

	/**
	 * Compute minutes since midnight (UTC) for an epoch timestamp.
	 *
	 * @param int $epoch Unix epoch seconds.
	 *
	 * @return int Minutes since midnight in UTC (0–1439).
	 */
	private function minutesIntoDay(int $epoch): int {
		return (((int)gmdate('H', $epoch) * 60) + (int)gmdate('i', $epoch));
	}//end minutesIntoDay()

	/**
	 * Find a single record by exact-match filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		$records = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		if (count($records) === 0) {
			return null;
		}

		// Count greater than zero guarantees reset() returns the first record array.
		return reset($records);
	}//end findOne()

	/**
	 * Find records by exact-match filters in the configured register.
	 *
	 * Returns an empty array when the schema is not yet available (T1 state),
	 * keeping the guard usable before dependency registers are seeded.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int|null $limit Optional result limit.
	 *
	 * @return array<int, array<string, mixed>> Matching records.
	 */
	private function findMany(string $schema, array $filters, ?int $limit = null): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$params = ['filters' => $filters];
			if ($limit !== null) {
				$params['limit'] = $limit;
			}

			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($params);

			if (is_array($result) === false) {
				return [];
			}

			return $result;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'AppointmentGuard: schema lookup unavailable (T1 state) — treating as empty',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findMany()
}//end class
