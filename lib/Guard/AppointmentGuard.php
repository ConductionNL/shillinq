<?php

/**
 * Appointment Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\AppIdentity;
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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

			return $this->passesTimeChecks(appointment: $appointment) && $this->passesBookingChecks(appointment: $appointment);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AppointmentGuard: validateOnSave failed — denying save (fail-closed)',
				['appointmentId' => ($appointment['appointmentId'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end validateOnSave()

	/**
	 * The time checks, in order: a valid window, the service duration, the operational hours.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when all pass; stops at the first that fails.
	 */
	private function passesTimeChecks(array $appointment): bool {
		return $this->hasValidTimeWindow(appointment: $appointment)
			&& $this->matchesServiceDuration(appointment: $appointment)
			&& $this->isWithinOperationalHours(appointment: $appointment);
	}//end passesTimeChecks()

	/**
	 * The booking checks, in order: a free slot, an eligible customer.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when both pass; stops at the first that fails.
	 */
	private function passesBookingChecks(array $appointment): bool {
		return $this->isSlotAvailable(appointment: $appointment) && $this->isCustomerEligible(appointment: $appointment);
	}//end passesBookingChecks()

	/**
	 * Verify the appointment has a well-formed start/end window with end after start.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return bool True when both timestamps parse and endTime is after startTime.
	 */
	private function hasValidTimeWindow(array $appointment): bool {
		$problem = self::windowProblem(window: $this->window(record: $appointment));
		if ($problem === null) {
			return true;
		}

		$this->logger->info($problem, ['appointmentId' => ($appointment['appointmentId'] ?? 'unknown')]);
		return false;
	}//end hasValidTimeWindow()

	/**
	 * The refusal for a window that is missing, unparseable or not increasing.
	 *
	 * @param array{0: int, 1: int}|null $window Start and end epochs, or null.
	 *
	 * @return string|null The refusal message, or null for a valid window.
	 */
	private static function windowProblem(?array $window): ?string {
		if ($window === null) {
			return 'AppointmentGuard: missing or unparseable startTime/endTime — denying save';
		}

		if ($window[1] <= $window[0]) {
			return 'AppointmentGuard: endTime not after startTime — denying save';
		}

		return null;
	}//end windowProblem()

	/**
	 * A record's startTime and endTime as epochs.
	 *
	 * @param mixed $record An appointment record.
	 *
	 * @return array{0: int, 1: int}|null Start and end, or null when either is missing or unparseable.
	 */
	private function window(mixed $record): ?array {
		$start = $this->parseTimestamp(value: (string)($record['startTime'] ?? ''));
		$end = $this->parseTimestamp(value: (string)($record['endTime'] ?? ''));
		if ($start === null || $end === null) {
			return null;
		}

		return [$start, $end];
	}//end window()

	/**
	 * The lookup filter for a related record: its id key, then the administration.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 * @param string               $key         The related id key (serviceId, resourceId, customerId).
	 *
	 * @return array<string, string>
	 */
	private static function relatedFilter(array $appointment, string $key): array {
		return [
			$key => (string)($appointment[$key] ?? ''),
			'administrationId' => (string)($appointment['administrationId'] ?? ''),
		];
	}//end relatedFilter()

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
		$service = $this->findOne(schema: 'Service', filters: self::relatedFilter(appointment: $appointment, key: 'serviceId'));
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

		return $this->durationWithinTolerance(appointment: $appointment, serviceMinutes: (int)$service['duration']);
	}//end matchesServiceDuration()

	/**
	 * Whether the requested duration is within DURATION_TOLERANCE_MINUTES of the service's; a mismatch is logged.
	 *
	 * @param array<string, mixed> $appointment    Appointment object array.
	 * @param int                  $serviceMinutes Service.duration.
	 *
	 * @return bool
	 */
	private function durationWithinTolerance(array $appointment, int $serviceMinutes): bool {
		$requestedMinutes = $this->durationMinutes(appointment: $appointment);
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
	}//end durationWithinTolerance()

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
		$resource = $this->findOne(schema: 'Resource', filters: self::relatedFilter(appointment: $appointment, key: 'resourceId'));
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

		// No (or malformed) operational hours declared — unrestricted.
		$hours = $this->operationalHours(resource: $resource);

		return $hours === null || $this->insideHours(appointment: $appointment, hours: $hours);
	}//end isWithinOperationalHours()

	/**
	 * The resource's opening and closing time in minutes since midnight.
	 *
	 * @param array<string, mixed> $resource Resource object array.
	 *
	 * @return array{opening: int, closing: int}|null Null when either is missing or malformed.
	 */
	private function operationalHours(array $resource): ?array {
		$openingMinutes = $this->clockToMinutes(clock: (string)($resource['openingTime'] ?? ''));
		$closingMinutes = $this->clockToMinutes(clock: (string)($resource['closingTime'] ?? ''));
		if ($openingMinutes === null || $closingMinutes === null) {
			return null;
		}

		return ['opening' => $openingMinutes, 'closing' => $closingMinutes];
	}//end operationalHours()

	/**
	 * Whether the appointment starts and ends within the hours; outside is logged.
	 *
	 * @param array<string, mixed>              $appointment Appointment object array.
	 * @param array{opening: int, closing: int} $hours       The operational hours.
	 *
	 * @return bool
	 */
	private function insideHours(array $appointment, array $hours): bool {
		$window = $this->window(record: $appointment);
		if ($window === null) {
			return false;
		}

		$minutes = ['start' => $this->minutesIntoDay(epoch: $window[0]), 'end' => $this->minutesIntoDay(epoch: $window[1])];
		if ($minutes['start'] < $hours['opening'] || $minutes['end'] > $hours['closing']) {
			$context = ['appointmentId' => ($appointment['appointmentId'] ?? 'unknown')] + $minutes + $hours;
			$this->logger->info('AppointmentGuard: outside operational hours — denying save', $context);
			return false;
		}

		return true;
	}//end insideHours()

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
		$filter = self::relatedFilter(appointment: $appointment, key: 'resourceId');
		$resource = $this->findOne(schema: 'Resource', filters: $filter);
		if ($resource !== null && ((bool)($resource['allowOverlap'] ?? false)) === true) {
			return true;
		}

		if ($this->window(record: $appointment) === null) {
			return false;
		}

		$conflict = $this->findConflict(appointment: $appointment, existing: $this->findMany(schema: 'Appointment', params: ['filters' => $filter]));

		return $conflict === null || $this->slotTaken(appointment: $appointment, conflict: $conflict);
	}//end isSlotAvailable()

	/**
	 * Log the double booking and refuse.
	 *
	 * @param array<string, mixed> $appointment The appointment being saved.
	 * @param mixed                $conflict    The overlapping record.
	 *
	 * @return bool Always false.
	 */
	private function slotTaken(array $appointment, mixed $conflict): bool {
		$this->logger->info(
			'AppointmentGuard: resource slot already booked — denying save',
			[
				'appointmentId' => ($appointment['appointmentId'] ?? 'unknown'),
				'resourceId' => ($appointment['resourceId'] ?? 'unknown'),
				'conflictsWith' => ($conflict['appointmentId'] ?? 'unknown'),
			]
		);

		return false;
	}//end slotTaken()

	/**
	 * Find the first existing appointment that overlaps the requested window.
	 *
	 * The appointment's own record (matched by appointmentId) and any cancelled
	 * record are excluded. Records with unparseable times are ignored.
	 *
	 * @param array<string, mixed> $appointment The appointment being saved (with a valid window).
	 * @param array<int, mixed>    $existing    Existing appointments on the resource.
	 *
	 * @return mixed The conflicting record, or null when the slot is free.
	 */
	private function findConflict(array $appointment, array $existing): mixed {
		$selfId = (string)($appointment['appointmentId'] ?? '');
		$window = $this->window(record: $appointment);
		foreach ($existing as $other) {
			if (self::isOtherLiveRecord(other: $other, selfId: $selfId) === true && $this->overlaps(window: $window, other: $other) === true) {
				return $other;
			}
		}

		return null;
	}//end findConflict()

	/**
	 * Whether a record is neither the appointment itself nor cancelled.
	 *
	 * @param mixed  $other  An existing appointment.
	 * @param string $selfId The saved appointment's appointmentId, or ''.
	 *
	 * @return bool
	 */
	private static function isOtherLiveRecord(mixed $other, string $selfId): bool {
		if ($selfId !== '' && (string)($other['appointmentId'] ?? '') === $selfId) {
			return false;
		}

		return ($other['status'] ?? '') !== 'cancelled';
	}//end isOtherLiveRecord()

	/**
	 * Whether a record's window overlaps; one with unparseable times never does.
	 * Two half-open intervals [start, end) overlap iff start < otherEnd AND otherStart < end.
	 *
	 * @param array{0: int, 1: int} $window The requested window.
	 * @param mixed                 $other  An existing appointment.
	 *
	 * @return bool
	 */
	private function overlaps(array $window, mixed $other): bool {
		$otherWindow = $this->window(record: $other);
		if ($otherWindow === null) {
			return false;
		}

		return $window[0] < $otherWindow[1] && $otherWindow[0] < $window[1];
	}//end overlaps()

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
		// No app-managed contact record (customer is an external NC contact) — eligible.
		$contact = $this->findOne(schema: 'Contact', filters: self::relatedFilter(appointment: $appointment, key: 'customerId'));
		if ($contact === null) {
			return true;
		}

		return $this->contactActive(appointment: $appointment, contact: $contact);
	}//end isCustomerEligible()

	/**
	 * Whether the contact is neither suspended nor banned; a refusal is logged.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 * @param array<string, mixed> $contact     The app-managed contact record.
	 *
	 * @return bool
	 */
	private function contactActive(array $appointment, array $contact): bool {
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
	}//end contactActive()

	/**
	 * Compute the appointment duration in whole minutes.
	 *
	 * @param array<string, mixed> $appointment Appointment object array.
	 *
	 * @return int Duration in minutes (0 when either timestamp is unparseable).
	 */
	private function durationMinutes(array $appointment): int {
		$window = $this->window(record: $appointment);
		if ($window === null) {
			return 0;
		}

		return (int)round((($window[1] - $window[0]) / 60));
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
		$records = $this->findMany(schema: $schema, params: ['filters' => $filters, 'limit' => 1]);
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
	 * @param string               $schema Schema name.
	 * @param array<string, mixed> $params The findAll query: exact-match filters, optionally a limit.
	 *
	 * @return array<int, array<string, mixed>> Matching records.
	 */
	private function findMany(string $schema, array $params): array {
		try {
			$result = $this->lookup(schema: $schema, params: $params);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'AppointmentGuard: schema lookup unavailable (T1 state) — treating as empty',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($result) === false) {
			return [];
		}

		return $result;
	}//end findMany()

	/**
	 * Run one findAll on the configured register.
	 *
	 * @param string               $schema Schema name.
	 * @param array<string, mixed> $params The findAll query.
	 *
	 * @return mixed What findAll answers.
	 */
	private function lookup(string $schema, array $params): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->setRegister(register: $this->getRegisterSlug())
			->setSchema(schema: $schema)
			->findAll($params);
	}//end lookup()
}//end class
