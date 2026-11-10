<?php

/**
 * Raises the compliance-deadline reminders of one dispatch run.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exactly one NC Notification per deadline per user inside its lead time
 * (REQ-CDC-007), deduplicated by a per-user sent map. Built by
 * ComplianceDeadlineCalendarService for one dispatch run.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineReminders {

	/**
	 * User-preference key holding the JSON map of already-sent reminders
	 * (uid => dueDate) so a daily run raises exactly ONE notification per
	 * deadline per user (REQ-CDC-007).
	 */
	private const PREF_SENT = 'deadline_reminders_sent';


	/**
	 * Construct the run.
	 *
	 * @param IConfig                           $config          User preferences (the sent map).
	 * @param INotificationManager              $notificationMgr Nextcloud notification manager.
	 * @param LoggerInterface                   $logger          Logger for fail-soft diagnostics.
	 * @param ComplianceDeadlineCalendarService $preferences     The category toggles and lead times.
	 * @param DateTimeInterface                 $today           Reference date.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly INotificationManager $notificationMgr,
		private readonly LoggerInterface $logger,
		private readonly ComplianceDeadlineCalendarService $preferences,
		private readonly DateTimeInterface $today,
	) {
	}//end __construct()

	/**
	 * Raise the due reminders for one user (REQ-CDC-007), then prune the
	 * sent map and persist it when it changed.
	 *
	 * @param string                          $userId    The NC user id.
	 * @param array<int,array<string,string>> $deadlines The open deadlines.
	 *
	 * @return int Number of notifications raised for this user.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function remindUser(string $userId, array $deadlines): int {
		$round = new DeadlineReminderRound();
		$round->userId = $userId;
		$round->today  = $this->today;
		$round->sent   = $this->readSentMap(userId: $userId);
		foreach ($deadlines as $deadline) {
			$round->openIds[$deadline['uid']] = true;
			$this->remindOf(round: $round, deadline: $deadline);
		}

		// Prune entries for deadlines that left the open set.
		foreach (array_keys($round->sent) as $uid) {
			if (isset($round->openIds[$uid]) === false) {
				unset($round->sent[$uid]);
				$round->dirty = true;
			}
		}

		if ($round->dirty === true) {
			$this->writeSentMap(userId: $round->userId, sent: $round->sent);
		}

		return $round->raised;
	}//end remindUser()

	/**
	 * Raise the reminder for one deadline when it is due within the lead time
	 * and not already sent for this dueDate; a moved deadline (different
	 * dueDate) re-arms it.
	 *
	 * @param DeadlineReminderRound $round    The user's round.
	 * @param array<string,string>  $deadline The deadline entry.
	 *
	 * @return void
	 */
	private function remindOf(DeadlineReminderRound $round, array $deadline): void {
		$daysUntil = $this->daysUntilWithinLead(round: $round, deadline: $deadline);
		if ($daysUntil === null || ($round->sent[$deadline['uid']] ?? null) === $deadline['dueDate']) {
			return;
		}

		if ($this->notifyUser(userId: $round->userId, deadline: $deadline, daysUntil: $daysUntil) === true) {
			$round->sent[$deadline['uid']] = $deadline['dueDate'];
			$round->dirty = true;
			$round->raised++;
		}
	}//end remindOf()

	/**
	 * Days until a deadline whose category the user enabled, when it is not
	 * past and falls inside the category's lead time; else null.
	 *
	 * @param DeadlineReminderRound $round    The user's round.
	 * @param array<string,string>  $deadline The deadline entry.
	 *
	 * @return int|null
	 */
	private function daysUntilWithinLead(DeadlineReminderRound $round, array $deadline): ?int {
		if ($this->preferences->isCategoryEnabled(userId: $round->userId, category: $deadline['category']) === false) {
			return null;
		}

		$daysUntil = $this->daysUntil(dueDate: $deadline['dueDate'], today: $round->today);
		if ($daysUntil === null || $daysUntil < 0) {
			return null;
		}

		if ($daysUntil > $this->preferences->leadTimeDays(userId: $round->userId, category: $deadline['category'])) {
			return null;
		}

		return $daysUntil;
	}//end daysUntilWithinLead()


	/**
	 * Emit one NC Notification for a deadline (REQ-CDC-007).
	 *
	 * @param string $userId The recipient NC user id.
	 * @param array<string,string> $deadline The deadline entry.
	 * @param int $daysUntil Days until the deadline.
	 *
	 * @return bool True when the notification was handed to the manager.
	 */
	private function notifyUser(string $userId, array $deadline, int $daysUntil): bool {
		try {
			$notification = $this->notificationMgr->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($userId)
				->setObject('compliance-deadline', substr($deadline['uid'], 0, 64))
				->setSubject(
					'deadline_reminder',
					[
						'summary' => $deadline['summary'],
						'dueDate' => $deadline['dueDate'],
						'category' => $deadline['category'],
						'daysUntil' => (string)$daysUntil,
					]
				)
				->setDateTime(new DateTime());
			$this->notificationMgr->notify($notification);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceDeadlineCalendarService: failed to raise deadline reminder',
				['userId' => $userId, 'uid' => $deadline['uid'], 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end notifyUser()


	/**
	 * Whole days from today until a due date (negative = past).
	 *
	 * @param string $dueDate The due date (Y-m-d).
	 * @param DateTimeInterface $today Reference date.
	 *
	 * @return int|null Day count, or null when unparseable.
	 */
	private function daysUntil(string $dueDate, DateTimeInterface $today): ?int {
		try {
			$due = (new DateTimeImmutable($dueDate))->setTime(0, 0);
			$todayDay = DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
			return (int)$todayDay->diff($due)->format('%r%a');
		} catch (Throwable $e) {
			return null;
		}

	}//end daysUntil()


	/**
	 * Read the per-user sent-reminders map (uid => dueDate).
	 *
	 * @param string $userId The NC user id.
	 *
	 * @return array<string,string> The sent map.
	 */
	private function readSentMap(string $userId): array {
		$raw = $this->config->getUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_SENT,
			default: ''
		);

		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $uid => $dueDate) {
			if (is_string($uid) === true && is_string($dueDate) === true) {
				$map[$uid] = $dueDate;
			}
		}

		return $map;
	}//end readSentMap()


	/**
	 * Persist the per-user sent-reminders map.
	 *
	 * @param string $userId The NC user id.
	 * @param array<string,string> $sent The sent map.
	 *
	 * @return void
	 */
	private function writeSentMap(string $userId, array $sent): void {
		try {
			$this->config->setUserValue(
				userId: $userId,
				appName: AppIdentity::APP_ID,
				key: self::PREF_SENT,
				value: (string)json_encode($sent)
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ComplianceDeadlineCalendarService: failed to persist sent-reminder map',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
		}

	}//end writeSentMap()
}//end class
