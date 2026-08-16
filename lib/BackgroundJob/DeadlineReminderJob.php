<?php

/**
 * Deadline Reminder Job
 *
 * Daily background job (REQ-CDC-007) that (1) publishes the open
 * compliance deadlines as VEVENTs into each seen user's deadline calendar
 * honouring the per-user category toggles (REQ-CDC-001 / REQ-CDC-006),
 * and (2) raises exactly one NC Notification per upcoming deadline per
 * user within the user's configured lead time. Both phases are delegated
 * to ComplianceDeadlineCalendarService; the job only sets the daily
 * interval and guards cron against an unavailable environment
 * (fail-soft, logged) — mirrors {@see TaxDeadlineReminderJob}.
 *
 * ADR-031: scheduled bulk work is an allowed imperative surface.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs once per day: publishes deadline VEVENTs and raises the due
 * deadline reminders (REQ-CDC-001 / REQ-CDC-007).
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
class DeadlineReminderJob extends TimedJob {
	/**
	 * Construct the job with a daily interval.
	 *
	 * @param ITimeFactory $time The Nextcloud time factory.
	 * @param ComplianceDeadlineCalendarService $calendarService The deadline publication + reminder service.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ComplianceDeadlineCalendarService $calendarService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Run once every 24 hours.
		$this->setInterval(seconds: (24 * 60 * 60));

	}//end __construct()

	/**
	 * Publish deadline VEVENTs + dispatch due reminders; never throw
	 * (a background job must not crash cron).
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$users = $this->calendarService->publishAll();
			$this->logger->debug('DeadlineReminderJob: published deadline VEVENTs for ' . $users . ' user(s)');
		} catch (\Throwable $e) {
			$this->logger->error(
				'DeadlineReminderJob: deadline publication failed',
				['exception' => $e->getMessage()]
			);
		}

		try {
			$count = $this->calendarService->dispatchDueReminders();
			$this->logger->debug('DeadlineReminderJob: raised ' . $count . ' deadline reminder(s)');
		} catch (\Throwable $e) {
			$this->logger->error(
				'DeadlineReminderJob: reminder dispatch failed',
				['exception' => $e->getMessage()]
			);
		}

	}//end run()
}//end class
