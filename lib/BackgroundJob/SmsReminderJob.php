<?php

/**
 * SMS Reminder Background Job
 *
 * Every fifteen minutes, hands the due booking SMS reminders to the
 * configured provider through SmsReminderRunner, which records each outcome
 * on its appointment and marks a channel not connected while no real
 * provider is bound (REQ-SMS-021).
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
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Service\Sms\SmsReminderRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the due booking SMS reminders.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md
 */
class SmsReminderJob extends TimedJob {
	/**
	 * Interval between runs, in seconds (15 minutes).
	 *
	 * @var int
	 */
	public const INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   The time factory.
	 * @param SmsReminderRunner $runner Sends the due reminders.
	 * @param LoggerInterface   $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SmsReminderRunner $runner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);

		// One run at a time, so an appointment is never reminded twice by overlapping cron passes.
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run the reminders once.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	protected function run(mixed $argument): void {
		try {
			$report = $this->runner->run(now: new DateTimeImmutable('@' . $this->time->getTime()));
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: SmsReminderJob failed', ['exception' => $e->getMessage()]);
			return;
		}

		$this->logger->info('Shillinq: SmsReminderJob complete', $report);
	}//end run()
}//end class
