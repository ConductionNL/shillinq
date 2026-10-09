<?php

/**
 * Scheduled Report Runner
 *
 * Produces every due report schedule (reporting-data-delivery REQ-RDD-002).
 * A schedule is due when it is active and its next run has passed. A run
 * produces the report through ReportGenerationService as the schedule's
 * owner, files and shares it through ScheduledReportDelivery, notifies the
 * recipients and moves the next run on. A failed run records the reason,
 * notifies the owner, and still moves the next run on, so one broken
 * schedule does not retry every hour.
 *
 * The owner is the object's OpenRegister owner. The job runs without a user
 * session and without RBAC, so a run whose owner cannot reach the
 * administration is refused: a schedule never produces a report its owner
 * could not produce by hand.
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the due report schedules.
 */
class ScheduledReportRunner {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService  OpenRegister object service.
	 * @param SettingsService              $settings       Resolves the register slug.
	 * @param ReportGenerationService      $reports        Produces the report.
	 * @param ScheduledReportDelivery      $delivery       Files, shares and notifies.
	 * @param ReportScheduleCalendar       $calendar       Next run and period.
	 * @param AdministrationContextService $administrations Who may reach an administration.
	 * @param LoggerInterface              $logger         Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly ReportGenerationService $reports,
		private readonly ScheduledReportDelivery $delivery,
		private readonly ReportScheduleCalendar $calendar,
		private readonly AdministrationContextService $administrations,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run every active schedule whose next run has passed.
	 *
	 * @param DateTimeImmutable $now The moment of this pass.
	 *
	 * @return array{ran: int, failed: int} Counts.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function runDue(DateTimeImmutable $now): array {
		$counts = ['ran' => 0, 'failed' => 0];
		$rows   = $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->settings->getRegisterSlug(),
			schema: 'ReportSchedule',
			filters: ['status' => 'active']
		);
		foreach ($rows as $row) {
			$schedule = $this->rowPayload(row: $row);
			$next     = (string)($schedule['nextRunAt'] ?? '');
			if ($next === '' || new DateTimeImmutable($next) > $now) {
				continue;
			}

			$schedule['id'] = ObjectIdentifier::resolve(saved: $row);
			$ownerId        = $this->owner(row: $row);
			if ($this->runOne(schedule: $schedule, ownerId: $ownerId, now: $now) === true) {
				$counts['ran']++;
				continue;
			}

			$counts['failed']++;
		}

		return $counts;

	}//end runDue()

	/**
	 * Run one schedule.
	 *
	 * @param array<string, mixed> $schedule The schedule, with its id.
	 * @param string               $ownerId  Its OpenRegister owner.
	 * @param DateTimeImmutable    $now      The moment of this pass.
	 *
	 * @return bool True when the report was produced and delivered.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function runOne(array $schedule, string $ownerId, DateTimeImmutable $now): bool {
		$runAt     = new DateTimeImmutable((string)$schedule['nextRunAt']);
		$frequency = (string)($schedule['frequency'] ?? 'monthly');
		$period    = $this->calendar->periodFor(
			frequency: $frequency,
			periodRule: (string)($schedule['periodRule'] ?? 'previous-period'),
			runAt: $runAt
		);
		$patch     = [
			'lastRunAt'  => $now->format(DATE_ATOM),
			'lastPeriod' => $period,
			'nextRunAt'  => $this->calendar->nextRunAfter(
				frequency: $frequency,
				runDay: (int)($schedule['runDay'] ?? 1),
				after: $now
			)->format(DATE_ATOM),
		];

		$error = $this->produceAndDeliver(schedule: $schedule, ownerId: $ownerId, period: $period, patch: $patch);
		$patch['lastError'] = $error;
		$this->save(scheduleId: (string)$schedule['id'], patch: $patch);
		if ($error === '') {
			return true;
		}

		$this->logger->warning('ScheduledReportRunner: run failed', ['schedule' => $schedule['id'], 'reason' => $error]);
		$this->delivery->notifyFailed(ownerId: $ownerId, schedule: $schedule, reason: $error);

		return false;

	}//end runOne()

	/**
	 * Produce the report and deliver it, returning why it failed or ''.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 * @param string               $ownerId  Its owner.
	 * @param string               $period   The period reported on.
	 * @param array<string, mixed> $patch    Filled with the filed path on success.
	 *
	 * @return string The reason for a failure, '' on success.
	 */
	private function produceAndDeliver(array $schedule, string $ownerId, string $period, array &$patch): string {
		$administrationId = (string)($schedule['administrationId'] ?? '');
		if ($ownerId === '' || $this->administrations->canUserAccess(userId: $ownerId, administrationId: $administrationId) === false) {
			return 'The schedule owner has no access to this administration.';
		}

		try {
			$produced = $this->reports->generate(
				reportType: (string)($schedule['reportType'] ?? ''),
				period: $period,
				administrationId: $administrationId,
				format: (string)($schedule['format'] ?? ''),
				asUserId: $ownerId
			);
			if (isset($produced['error']) === true) {
				return (string)($produced['message'] ?? $produced['error']);
			}

			if (($produced['fileId'] ?? null) === null) {
				return 'The report was produced but its file could not be stored.';
			}

			$recipients = array_map('strval', (array)($schedule['recipients'] ?? []));
			$filed      = $this->delivery->file(
				ownerId: $ownerId,
				fileId: (int)$produced['fileId'],
				folderPath: (string)($schedule['folderPath'] ?? '/Shillinq/Reports'),
				recipients: $recipients
			);
			$patch['lastFilePath'] = $filed['path'];
			$this->delivery->notifyReady(recipients: $recipients, schedule: $schedule, period: $period, fileId: $filed['fileId']);
		} catch (Throwable $e) {
			return $e->getMessage();
		}//end try

		return '';

	}//end produceAndDeliver()

	/**
	 * Write the run's outcome onto the schedule.
	 *
	 * @param string               $scheduleId The schedule.
	 * @param array<string, mixed> $patch      The fields.
	 *
	 * @return void
	 */
	private function save(string $scheduleId, array $patch): void {
		$this->objectService->patchObject(
			objectId: $scheduleId,
			data: $patch,
			register: $this->settings->getRegisterSlug(),
			schema: 'ReportSchedule',
			_rbac: false,
			_multitenancy: false
		);

	}//end save()

	/**
	 * The OpenRegister owner of a row.
	 *
	 * @param mixed $row The row.
	 *
	 * @return string The owner, '' when unknown.
	 */
	private function owner(mixed $row): string {
		if (is_object($row) === true) {
			try {
				return (string)($row->getOwner() ?? '');
			} catch (Throwable) {
				return '';
			}
		}

		return (string)($row['@self']['owner'] ?? '');

	}//end owner()
}//end class
