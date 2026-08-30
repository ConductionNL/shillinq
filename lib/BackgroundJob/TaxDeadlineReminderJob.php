<?php

/**
 * Tax Deadline Reminder Job
 *
 * Daily background job (REQ-VPB-013) that dispatches Vpb deadline reminders 7
 * days and 1 day before each open deadline. Delegates the scan + dispatch to
 * TaxNotificationService; the job itself only sets the daily interval and guards
 * against an OpenRegister-unavailable environment (fail-closed, logged).
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\TaxNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs once per day and dispatches due Vpb deadline reminders (REQ-VPB-013).
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
 */
class TaxDeadlineReminderJob extends TimedJob {
	/**
	 * Construct the job with a daily interval.
	 *
	 * @param ITimeFactory $time The Nextcloud time factory.
	 * @param TaxNotificationService $notificationService The deadline reminder dispatcher.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TaxNotificationService $notificationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Run once every 24 hours.
		$this->setInterval(seconds: (24 * 60 * 60));

	}//end __construct()

	/**
	 * Dispatch due reminders; never throw (a background job must not crash cron).
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$count = $this->notificationService->dispatchDueReminders();
			$this->logger->debug('TaxDeadlineReminderJob: dispatched ' . $count . ' deadline reminder(s)');
		} catch (\Throwable $e) {
			$this->logger->error(
				'TaxDeadlineReminderJob: failed to dispatch deadline reminders',
				['exception' => $e->getMessage()]
			);
		}

	}//end run()
}//end class
