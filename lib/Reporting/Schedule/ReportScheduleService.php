<?php

/**
 * Report Schedule Service
 *
 * Creates and changes report schedules on behalf of their owner. The server
 * sets the owner, the status of a new schedule and the next run; only the
 * owner changes a schedule, and its recipients are limited to what the owner
 * may share (ScheduleRecipientPolicy).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCP\IL10N;
use OutOfBoundsException;
use Throwable;

/**
 * The write path of ReportSchedule.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
class ReportScheduleService {

	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'ReportSchedule';

	/**
	 * The fields an owner may change on an existing schedule.
	 *
	 * @var list<string>
	 */
	private const CHANGEABLE = ['name', 'format', 'frequency', 'runDay', 'periodRule', 'recipients', 'folderPath', 'status'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService   OpenRegister object service.
	 * @param SettingsService              $settings        Resolves the register slug.
	 * @param AdministrationContextService $administrations Answers administration access.
	 * @param ScheduleRecipientPolicy      $recipients      Limits the recipients.
	 * @param ReportScheduleCalendar       $calendar        Computes the next run.
	 * @param IL10N                        $l10n            Translates refusals.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $administrations,
		private readonly ScheduleRecipientPolicy $recipients,
		private readonly ReportScheduleCalendar $calendar,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Create a schedule owned by the caller.
	 *
	 * @param string               $ownerId The caller.
	 * @param array<string, mixed> $input   administrationId, reportType, format, frequency, runDay, periodRule, recipients, folderPath, name.
	 * @param DateTimeImmutable    $now     The moment of saving.
	 *
	 * @return array<string, mixed> The saved schedule.
	 *
	 * @throws OutOfBoundsException     When the caller has no access to the administration.
	 * @throws InvalidArgumentException When a field is refused.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function create(string $ownerId, array $input, DateTimeImmutable $now): array {
		$administrationId = trim((string)($input['administrationId'] ?? ''));
		if ($administrationId === '' || $this->administrations->canUserAccess(userId: $ownerId, administrationId: $administrationId) === false) {
			throw new OutOfBoundsException('Administration not found');
		}

		$reportType = (string)($input['reportType'] ?? '');
		$report     = ReportCatalogue::byId($reportType);
		if ($report === null) {
			throw new InvalidArgumentException($this->l10n->t('Unknown report type'));
		}

		$schedule = $this->validated(
			ownerId: $ownerId,
			administrationId: $administrationId,
			report: $report,
			fields: [
				'name'       => (string)($input['name'] ?? $report['label']),
				'format'     => (string)($input['format'] ?? ''),
				'frequency'  => (string)($input['frequency'] ?? ''),
				'runDay'     => ($input['runDay'] ?? 1),
				'periodRule' => (string)($input['periodRule'] ?? 'previous-period'),
				'recipients' => ($input['recipients'] ?? []),
				'folderPath' => (string)($input['folderPath'] ?? '/Shillinq/Reports'),
				'status'     => 'active',
			]
		);
		$schedule['administrationId'] = $administrationId;
		$schedule['reportType']       = $reportType;
		$schedule['nextRunAt']        = $this->nextRun(schedule: $schedule, now: $now);

		$saved = $this->objectService->saveObject(
			object: $schedule,
			register: $this->settings->getRegisterSlug(),
			schema: self::SCHEMA
		);

		return $saved->getObject();

	}//end create()

	/**
	 * Change a schedule the caller owns.
	 *
	 * @param string               $ownerId    The caller.
	 * @param string               $scheduleId The schedule.
	 * @param array<string, mixed> $input      Any of CHANGEABLE.
	 * @param DateTimeImmutable    $now        The moment of saving.
	 *
	 * @return array<string, mixed> The saved schedule.
	 *
	 * @throws OutOfBoundsException     When the schedule does not exist or the caller does not own it.
	 * @throws InvalidArgumentException When a field is refused.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function update(string $ownerId, string $scheduleId, array $input, DateTimeImmutable $now): array {
		$found = null;
		try {
			$found = $this->objectService->find(
				id: $scheduleId,
				register: $this->settings->getRegisterSlug(),
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			$found = null;
		}

		if ($found === null || (string)($found->getOwner() ?? '') !== $ownerId) {
			throw new OutOfBoundsException('Schedule not found');
		}

		$current          = $found->getObject();
		$administrationId = (string)($current['administrationId'] ?? '');
		if ($this->administrations->canUserAccess(userId: $ownerId, administrationId: $administrationId) === false) {
			throw new OutOfBoundsException('Schedule not found');
		}

		$report = ReportCatalogue::byId((string)($current['reportType'] ?? ''));
		if ($report === null) {
			throw new InvalidArgumentException($this->l10n->t('Unknown report type'));
		}

		$fields = [];
		foreach (self::CHANGEABLE as $field) {
			$fields[$field] = ($current[$field] ?? null);
			if (array_key_exists($field, $input) === true) {
				$fields[$field] = $input[$field];
			}
		}

		$patch = $this->validated(ownerId: $ownerId, administrationId: $administrationId, report: $report, fields: $fields);
		if ($patch['frequency'] !== ($current['frequency'] ?? null) || $patch['runDay'] !== (int)($current['runDay'] ?? 0)) {
			$patch['nextRunAt'] = $this->nextRun(schedule: $patch, now: $now);
		}

		$saved = $this->objectService->patchObject(
			objectId: $scheduleId,
			data: $patch,
			register: $this->settings->getRegisterSlug(),
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $saved->getObject();

	}//end update()

	/**
	 * Check and normalise the changeable fields.
	 *
	 * @param string               $ownerId          The owner.
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $report           The catalogue entry.
	 * @param array<string, mixed> $fields           The CHANGEABLE fields.
	 *
	 * @return array<string, mixed> The normalised fields.
	 *
	 * @throws InvalidArgumentException When a field is refused.
	 */
	private function validated(string $ownerId, string $administrationId, array $report, array $fields): array {
		$format = (string)$fields['format'];
		if (in_array($format, (array)$report['formats'], true) === false) {
			throw new InvalidArgumentException($this->l10n->t('This report is not available in that format'));
		}

		$frequency = (string)$fields['frequency'];
		if (in_array($frequency, ['weekly', 'monthly', 'quarterly'], true) === false) {
			throw new InvalidArgumentException($this->l10n->t('Choose weekly, monthly or quarterly'));
		}

		$runDay  = (int)$fields['runDay'];
		$lastDay = 28;
		if ($frequency === 'weekly') {
			$lastDay = 7;
		}

		if ($runDay < 1 || $runDay > $lastDay) {
			throw new InvalidArgumentException($this->l10n->t('The run day is out of range'));
		}

		$periodRule = (string)$fields['periodRule'];
		if (in_array($periodRule, ['previous-period', 'year-to-date'], true) === false) {
			throw new InvalidArgumentException($this->l10n->t('Choose the previous period or the year to date'));
		}

		$status = (string)$fields['status'];
		if (in_array($status, ['active', 'paused'], true) === false) {
			throw new InvalidArgumentException($this->l10n->t('Choose active or paused'));
		}

		$recipients = array_values(array_unique(array_map('strval', (array)$fields['recipients'])));
		$refused    = $this->recipients->refused(ownerId: $ownerId, administrationId: $administrationId, recipients: $recipients);
		if ($refused !== []) {
			throw new InvalidArgumentException($this->l10n->t('These recipients cannot receive this report: %s', [implode(', ', $refused)]));
		}

		$folderPath = trim((string)$fields['folderPath']);
		if ($folderPath === '' || str_contains($folderPath, '..') === true) {
			throw new InvalidArgumentException($this->l10n->t('Choose a folder'));
		}

		return [
			'name'       => trim((string)$fields['name']),
			'format'     => $format,
			'frequency'  => $frequency,
			'runDay'     => $runDay,
			'periodRule' => $periodRule,
			'recipients' => $recipients,
			'folderPath' => $folderPath,
			'status'     => $status,
		];

	}//end validated()

	/**
	 * The first run of a schedule after a moment.
	 *
	 * @param array<string, mixed> $schedule The schedule with frequency and runDay.
	 * @param DateTimeImmutable    $now      The moment.
	 *
	 * @return string The run as an ISO 8601 date-time.
	 */
	private function nextRun(array $schedule, DateTimeImmutable $now): string {
		return $this->calendar->nextRunAfter(
			frequency: (string)$schedule['frequency'],
			runDay: (int)$schedule['runDay'],
			after: $now
		)->format(DATE_ATOM);

	}//end nextRun()
}//end class
