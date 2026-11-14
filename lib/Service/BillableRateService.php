<?php

/**
 * Billable Rate Service
 *
 * Prices one logged hour at the sell rate of the person on the project. The
 * rate comes from the rate card the user edits: the `RateCardTemplate` named
 * by the person's `ProjectAssignment`, the `RateCardVersion` effective on the
 * hour's date, and the first active `RateSchedule` of that version in the
 * order user, role, project, client, blended.
 *
 * An hour without a rate is an answer, not a default amount: the result says
 * "no rate" with one of four reasons (no assignment, no rate card, no version
 * on the date, no schedule matches), so the page can show why and the invoice
 * draft can refuse the hour. Every resolved rate is written as a `RateRecord`,
 * the audit record of which tier and which schedule priced the hour.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Resolves the billable rate of an hour and records the resolution.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.1
 */
final class BillableRateService {
	/**
	 * The hour has a rate.
	 *
	 * @var string
	 */
	public const STATUS_RATED = 'rated';

	/**
	 * The hour has no rate.
	 *
	 * @var string
	 */
	public const STATUS_NO_RATE = 'no-rate';

	/**
	 * The person is not assigned to the project on the hour's date.
	 *
	 * @var string
	 */
	public const REASON_NO_ASSIGNMENT = 'no-assignment';

	/**
	 * The assignment names no rate card of this administration.
	 *
	 * @var string
	 */
	public const REASON_NO_RATE_CARD = 'no-rate-card';

	/**
	 * No version of the rate card is effective on the hour's date.
	 *
	 * @var string
	 */
	public const REASON_NO_VERSION = 'no-version';

	/**
	 * No active schedule of the version matches the hour.
	 *
	 * @var string
	 */
	public const REASON_NO_SCHEDULE = 'no-schedule';

	/**
	 * The tiers, first match wins (REQ-RATE-005).
	 *
	 * @var array<int, string>
	 */
	public const TIERS = ['user', 'role', 'project', 'client', 'blended'];

	/**
	 * The units that price an hour.
	 *
	 * @var array<int, string>
	 */
	private const HOUR_UNITS = ['hourly', 'daily'];

	/**
	 * The version states a past or present hour may be priced by.
	 *
	 * @var array<int, string>
	 */
	private const PRICING_VERSION_STATES = ['active', 'expired'];

	/**
	 * Hours in a working day when the assignment names no capacity.
	 *
	 * @var float
	 */
	private const HOURS_PER_DAY = 8.0;

	/**
	 * Rows read per lookup.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param ITimeFactory           $timeFactory      The clock, for the record's timestamp.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Resolve the billable rate of one hour.
	 *
	 * The hour carries `personId`, `projectId`, `date` (Y-m-d), `hours` and
	 * `administrationId`, and may carry `clientId` (the project's customer, for
	 * the client tier) and `personName` (for the reason).
	 *
	 * @param array<string, mixed> $hour The hour.
	 *
	 * @return array<string, mixed> `status` rated with `rate`, `unit`, `tier`,
	 *                              `scheduleId`, `value` and `recordId`; or
	 *                              `status` no-rate with `reason` and `message`.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.1
	 */
	public function resolve(array $hour): array {
		$lookup = [
			'personId' => trim((string)($hour['personId'] ?? '')),
			'projectId' => trim((string)($hour['projectId'] ?? '')),
			'date' => trim((string)($hour['date'] ?? '')),
			'administrationId' => trim((string)($hour['administrationId'] ?? '')),
		];
		$date = $lookup['date'];
		$person = trim((string)($hour['personName'] ?? ''));
		if ($person === '') {
			$person = $lookup['personId'];
		}

		$assignment = $this->assignment(lookup: $lookup);
		if ($assignment === null) {
			return $this->noRate(reason: self::REASON_NO_ASSIGNMENT, message: "{$person} is not assigned to this project");
		}

		$template = $this->template(rateCardId: trim((string)($assignment['rateCardId'] ?? '')), administrationId: $lookup['administrationId']);
		if ($template === null) {
			return $this->noRate(reason: self::REASON_NO_RATE_CARD, message: "The assignment of {$person} names no rate card");
		}

		$version = $this->version(template: $template, lookup: $lookup);
		if ($version === null) {
			return $this->noRate(reason: self::REASON_NO_VERSION, message: "The rate card has no version effective on {$date}");
		}

		$lookup['entities'] = [
			'user' => $lookup['personId'],
			'role' => trim((string)($assignment['billingRoleId'] ?? '')),
			'project' => $lookup['projectId'],
			'client' => trim((string)($hour['clientId'] ?? '')),
			'blended' => '',
		];
		$match = $this->schedule(version: $version, lookup: $lookup);
		if ($match === null) {
			return $this->noRate(reason: self::REASON_NO_SCHEDULE, message: "No rate of the rate card applies to {$person} on {$date}");
		}

		[$tier, $schedule] = $match;
		$rate = (float)($schedule['rate'] ?? 0);
		$unit = (string)($schedule['unit'] ?? 'hourly');
		$value = round($rate * ((float)($hour['hours'] ?? 0) / $this->hoursPerUnit(unit: $unit, assignment: $assignment)), 2);
		$recordId = $this->record(hour: $hour, match: ['tier' => $tier, 'schedule' => $schedule, 'entities' => $lookup['entities']]);

		return [
			'status' => self::STATUS_RATED,
			'rate' => $rate,
			'unit' => $unit,
			'tier' => $tier,
			'scheduleId' => $this->idOf(row: $schedule, key: 'scheduleId'),
			'value' => $value,
			'recordId' => $recordId,
		];
	}//end resolve()

	/**
	 * The person's assignment to the project that covers the date.
	 *
	 * @param array<string, string> $lookup The hour's `personId`, `projectId`, `date` and `administrationId`.
	 *
	 * @return array<string, mixed>|null The assignment.
	 */
	private function assignment(array $lookup): ?array {
		$personId = $lookup['personId'];
		$projectId = $lookup['projectId'];
		$administrationId = $lookup['administrationId'];
		if ($personId === '' || $projectId === '' || $administrationId === '') {
			return null;
		}

		$rows = $this->read(
			schema: 'ProjectAssignment',
			filters: ['personId' => $personId, 'projectId' => $projectId, 'administrationId' => $administrationId]
		);
		foreach ($rows as $row) {
			if ((string)($row['personId'] ?? '') === $personId
				&& (string)($row['projectId'] ?? '') === $projectId
				&& $this->covers(window: [(string)($row['startDate'] ?? ''), (string)($row['endDate'] ?? '')], date: $lookup['date']) === true
			) {
				return $row;
			}
		}

		return null;
	}//end assignment()

	/**
	 * The rate card template the assignment names, in this administration only.
	 *
	 * @param string $rateCardId       The template's id or business key.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The template.
	 */
	private function template(string $rateCardId, string $administrationId): ?array {
		if ($rateCardId === '') {
			return null;
		}

		foreach ($this->read(schema: 'RateCardTemplate', filters: ['administrationId' => $administrationId]) as $row) {
			if ((string)($row['id'] ?? '') === $rateCardId || (string)($row['templateId'] ?? '') === $rateCardId) {
				return $row;
			}
		}

		return null;
	}//end template()

	/**
	 * The template's version effective on the date: the latest one whose window covers it.
	 *
	 * @param array<string, mixed> $template The template.
	 * @param array<string, mixed> $lookup   The hour's `date` and `administrationId`.
	 *
	 * @return array<string, mixed>|null The version.
	 */
	private function version(array $template, array $lookup): ?array {
		$keys = $this->keysOf(row: $template, key: 'templateId');
		$best = null;
		foreach ($this->read(schema: 'RateCardVersion', filters: ['administrationId' => $lookup['administrationId']]) as $row) {
			if (in_array((string)($row['templateId'] ?? ''), $keys, true) === false
				|| in_array((string)($row['status'] ?? ''), self::PRICING_VERSION_STATES, true) === false
				|| $this->covers(window: [(string)($row['effectiveDate'] ?? ''), (string)($row['expiryDate'] ?? '')], date: $lookup['date']) === false
			) {
				continue;
			}

			if ($best === null || (string)$row['effectiveDate'] > (string)$best['effectiveDate']) {
				$best = $row;
			}
		}

		return $best;
	}//end version()

	/**
	 * The first active schedule of the version that matches the hour, by tier.
	 *
	 * @param array<string, mixed> $version The version.
	 * @param array<string, mixed> $lookup  The hour's `date`, `administrationId` and `entities` (the entity each tier matches).
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The tier and the schedule.
	 */
	private function schedule(array $version, array $lookup): ?array {
		$keys = $this->keysOf(row: $version, key: 'versionId');
		$candidates = array_filter(
			$this->read(schema: 'RateSchedule', filters: ['administrationId' => $lookup['administrationId']]),
			fn (array $row): bool => in_array((string)($row['versionId'] ?? ''), $keys, true) === true
				&& (string)($row['status'] ?? '') === 'active'
				&& in_array((string)($row['unit'] ?? ''), self::HOUR_UNITS, true) === true
				&& $this->covers(window: [(string)($row['effectiveDate'] ?? ''), (string)($row['expiryDate'] ?? '')], date: $lookup['date']) === true
		);

		foreach (self::TIERS as $tier) {
			$entity = $lookup['entities'][$tier];
			if ($tier !== 'blended' && $entity === '') {
				continue;
			}

			foreach ($candidates as $row) {
				if ((string)($row['tier'] ?? '') === $tier && trim((string)($row['entityId'] ?? '')) === $entity) {
					return [$tier, $row];
				}
			}
		}

		return null;
	}//end schedule()

	/**
	 * The hours in one unit a rate is priced per: one hour, or the assignment's working day.
	 *
	 * @param string               $unit       The schedule's unit.
	 * @param array<string, mixed> $assignment The assignment.
	 *
	 * @return float The hours per unit.
	 */
	private function hoursPerUnit(string $unit, array $assignment): float {
		if ($unit !== 'daily') {
			return 1.0;
		}

		$perDay = self::HOURS_PER_DAY;
		$capacity = (float)($assignment['capacityHoursPerWeek'] ?? 0);
		if ($capacity > 0) {
			$perDay = ($capacity / 5);
		}

		return $perDay;
	}//end hoursPerUnit()

	/**
	 * Write the audit record of this resolution.
	 *
	 * @param array<string, mixed> $hour  The hour.
	 * @param array<string, mixed> $match The winning `tier` and `schedule`, and the `entities` each tier matched against.
	 *
	 * @return string The record id.
	 */
	private function record(array $hour, array $match): string {
		$tier = $match['tier'];
		$schedule = $match['schedule'];
		$entities = $match['entities'];
		$recordId = 'rr-' . bin2hex(random_bytes(8));
		$expiry = trim((string)($schedule['expiryDate'] ?? ''));
		if ($expiry === '') {
			$expiry = null;
		}

		$this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('RateRecord');
		$this->objectService->saveObject(
			object: [
				'recordId' => $recordId,
				'lookupDate' => (string)($hour['date'] ?? ''),
				'userId' => $entities['user'],
				'roleId' => $entities['role'],
				'projectId' => $entities['project'],
				'clientId' => $entities['client'],
				'resolvedTier' => $tier,
				'resolvedScheduleId' => $this->idOf(row: $schedule, key: 'scheduleId'),
				'resolvedRate' => (float)($schedule['rate'] ?? 0),
				'resolvedUnit' => (string)($schedule['unit'] ?? ''),
				'effectiveWindowStart' => (string)($schedule['effectiveDate'] ?? ''),
				'effectiveWindowEnd' => $expiry,
				'administrationId' => (string)($hour['administrationId'] ?? ''),
				'createdAt' => $this->timeFactory->getDateTime()->format(DateTimeInterface::ATOM),
			],
			register: $this->registerLocation->getRegisterSlug(),
			schema: 'RateRecord'
		);

		return $recordId;
	}//end record()

	/**
	 * The administration-scoped rows of a schema, as records.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters, always including the administration.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(string $schema, array $filters): array {
		$administrationId = (string)($filters['administrationId'] ?? '');
		$rows = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema)->findAll(
			['filters' => $filters, 'limit' => self::READ_LIMIT]
		);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// A row of another administration never prices an hour, even when
			// the store ignored the filter.
			if ($record !== null && (string)($record['administrationId'] ?? '') === $administrationId) {
				$records[] = $record;
			}
		}

		return $records;
	}//end read()

	/**
	 * Whether a date lies in an inclusive window; an empty end is open.
	 *
	 * @param array{0: string, 1: string} $window The first day (empty for none) and the last day (empty for open-ended).
	 * @param string                      $date   The date.
	 *
	 * @return bool True when the date is inside.
	 */
	private function covers(array $window, string $date): bool {
		if ($date === '') {
			return false;
		}

		$start = substr(trim($window[0]), 0, 10);
		$end = substr(trim($window[1]), 0, 10);

		return ($start === '' || $start <= $date) && ($end === '' || $date <= $end);
	}//end covers()

	/**
	 * The keys a child row may name its parent by: its id and its business key.
	 *
	 * @param array<string, mixed> $row The parent.
	 * @param string               $key The business key property.
	 *
	 * @return array<int, string> The non-empty keys.
	 */
	private function keysOf(array $row, string $key): array {
		return array_values(array_filter([(string)($row['id'] ?? ''), (string)($row[$key] ?? '')], static fn (string $value): bool => $value !== ''));
	}//end keysOf()

	/**
	 * A row's business key, else its id.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $key The business key property.
	 *
	 * @return string The key.
	 */
	private function idOf(array $row, string $key): string {
		$value = (string)($row[$key] ?? '');
		if ($value !== '') {
			return $value;
		}

		return (string)($row['id'] ?? '');
	}//end idOf()

	/**
	 * A "no rate" answer.
	 *
	 * @param string $reason  The reason code.
	 * @param string $message The reason in words.
	 *
	 * @return array<string, string> The answer.
	 */
	private function noRate(string $reason, string $message): array {
		return ['status' => self::STATUS_NO_RATE, 'reason' => $reason, 'message' => $message];
	}//end noRate()
}//end class
