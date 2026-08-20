<?php

/**
 * Tax Notification Service
 *
 * Tier-2 Vpb deadline-reminder dispatch (REQ-VPB-013). Scans pending TaxDeadline
 * records and dispatches a Nextcloud notification when a deadline falls inside a
 * reminder window (7 days or 1 day before the deadline date). Deadlines are read
 * via the real OpenRegister ObjectService API (findAll); notifications are
 * emitted through OCP\Notification\IManager so they respect the user's
 * notification preferences and surface in the Nextcloud notification UI (design
 * D5). The same deadline is not re-notified for the same window because the
 * window is an exact day-count match (7 or 1), so a daily run fires each window
 * once.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Dispatches Vpb deadline reminders (REQ-VPB-013).
 *
 * The reminder windows are fixed at 7 and 1 calendar days before a deadline's
 * deadlineDate. Only deadlines whose status is 'pending' or 'submitted' (i.e.
 * not yet filed/archived) are eligible. The notification object id is the
 * deadline slug/id so the client can deep-link to the deadline detail page.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
 */
class TaxNotificationService {
	/**
	 * Reminder windows in days before the deadline (REQ-VPB-013).
	 *
	 * @var array<int,int>
	 */
	private const REMINDER_DAYS = [7, 1];

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IManager $notificationMgr Nextcloud notification manager.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IManager $notificationMgr,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Dispatch reminders for all deadlines falling inside a reminder window.
	 *
	 * @param DateTimeInterface|null $now Reference "today" (defaults to now); injectable for tests.
	 *
	 * @return int The number of reminders dispatched.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
	 */
	public function dispatchDueReminders(?DateTimeInterface $now = null): int {
		$today = ($now ?? new DateTimeImmutable('today'));
		$deadlines = $this->fetchPendingDeadlines();

		$dispatched = 0;
		foreach ($deadlines as $deadline) {
			$window = $this->reminderWindow(deadline: $deadline, today: $today);
			if ($window === null) {
				continue;
			}

			$this->dispatch(deadline: $deadline, daysBefore: $window);
			$dispatched++;
		}

		return $dispatched;
	}//end dispatchDueReminders()

	/**
	 * Determine the reminder window (7 or 1) a deadline falls into today, or null.
	 *
	 * @param array<string,mixed> $deadline The TaxDeadline record.
	 * @param DateTimeInterface $today Reference date.
	 *
	 * @return int|null The matching window in days, or null when no window matches.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-37
	 */
	public function reminderWindow(array $deadline, DateTimeInterface $today): ?int {
		$deadlineDate = (string)($deadline['deadlineDate'] ?? '');
		if ($deadlineDate === '') {
			return null;
		}

		$due = strtotime($deadlineDate);
		if ($due === false) {
			return null;
		}

		$dueDay = (new DateTimeImmutable('@' . $due))->setTime(0, 0);
		$todayDay = DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
		$diffDays = (int)$todayDay->diff($dueDay)->format('%r%a');

		if (in_array($diffDays, self::REMINDER_DAYS, true) === true) {
			return $diffDays;
		}

		return null;
	}//end reminderWindow()

	/**
	 * Emit a notification for one deadline + window via the NC notification manager.
	 *
	 * @param array<string,mixed> $deadline The TaxDeadline record.
	 * @param int $daysBefore The reminder window in days.
	 *
	 * @return void
	 */
	private function dispatch(array $deadline, int $daysBefore): void {
		$objectId = (string)($deadline['@self']['slug'] ?? ($deadline['id'] ?? ''));
		if ($objectId === '') {
			return;
		}

		try {
			$notification = $this->notificationMgr->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject('tax-deadline', $objectId)
				->setSubject(
					'vpb_deadline_reminder',
					[
						'deadlineType' => (string)($deadline['deadlineType'] ?? ''),
						'daysBefore' => $daysBefore,
						'deadlineDate' => (string)($deadline['deadlineDate'] ?? ''),
					]
				)
				->setDateTime(new DateTime());
			$this->notificationMgr->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TaxNotificationService: failed to dispatch deadline reminder',
				['objectId' => $objectId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end dispatch()

	/**
	 * Fetch deadlines that are still open (pending or submitted).
	 *
	 * @return array<int,array<string,mixed>> Open TaxDeadline records.
	 */
	private function fetchPendingDeadlines(): array {
		$deadlines = $this->objectService
			->setRegister($this->register())
			->setSchema('TaxDeadline')
			->findAll([]);

		$open = [];
		foreach ($deadlines as $deadline) {
			$status = (string)($deadline['status'] ?? 'pending');
			if ($status === 'pending' || $status === 'submitted') {
				$open[] = $deadline;
			}
		}

		return $open;
	}//end fetchPendingDeadlines()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
