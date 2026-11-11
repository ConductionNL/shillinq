<?php

/**
 * One user's deadline-reminder round.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The user, the reference date and the per-user sent-reminder map (uid =>
 * dueDate) that keeps a daily run at exactly ONE notification per deadline
 * per user (REQ-CDC-007). Built by ComplianceDeadlineCalendarService for
 * each user of a dispatch run.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineReminderRound {

	/**
	 * User-preference key holding the JSON map of already-sent reminders
	 * (uid => dueDate) so a daily run raises exactly ONE notification per
	 * deadline per user (REQ-CDC-007).
	 */
	private const PREF_SENT = 'deadline_reminders_sent';

	/**
	 * The NC user id.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * Reference date.
	 *
	 * @var DateTimeInterface
	 */
	public DateTimeInterface $today;

	/**
	 * The sent map (uid => dueDate).
	 *
	 * @var array<string,string>
	 */
	public array $sent = [];

	/**
	 * The uids of the deadlines still open.
	 *
	 * @var array<string,true>
	 */
	public array $openIds = [];

	/**
	 * Notifications raised this round.
	 *
	 * @var int
	 */
	public int $raised = 0;

	/**
	 * Whether the sent map changed.
	 *
	 * @var bool
	 */
	public bool $dirty = false;

	/**
	 * Construct the round.
	 *
	 * @param IConfig         $config User preferences (the sent map).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the user's sent-reminders map; a missing or unreadable map is
	 * empty, and entries that are not uid => dueDate strings are dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function loadSentMap(): void {
		$raw = $this->config->getUserValue(
			userId: $this->userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_SENT,
			default: ''
		);

		$this->sent = $this->sentMapFrom(raw: $raw);
	}//end loadSentMap()

	/**
	 * Prune entries for deadlines that left the open set, then persist the
	 * map when it changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function pruneAndSave(): void {
		foreach (array_keys($this->sent) as $uid) {
			if (isset($this->openIds[$uid]) === false) {
				unset($this->sent[$uid]);
				$this->dirty = true;
			}
		}

		if ($this->dirty === true) {
			$this->saveSentMap();
		}
	}//end pruneAndSave()

	/**
	 * Whole days from today until a due date (negative = past).
	 *
	 * @param string $dueDate The due date (Y-m-d).
	 *
	 * @return int|null Day count, or null when unparseable.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function daysUntil(string $dueDate): ?int {
		try {
			$due = (new DateTimeImmutable($dueDate))->setTime(0, 0);
			$todayDay = DateTimeImmutable::createFromInterface($this->today)->setTime(0, 0);
			return (int)$todayDay->diff($due)->format('%r%a');
		} catch (Throwable $e) {
			return null;
		}

	}//end daysUntil()

	/**
	 * The sent map in a stored JSON value.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return array<string,string>
	 */
	private function sentMapFrom(string $raw): array {
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_filter(
			$decoded,
			static fn (mixed $dueDate, mixed $uid): bool => is_string($uid) === true && is_string($dueDate) === true,
			ARRAY_FILTER_USE_BOTH
		);
	}//end sentMapFrom()

	/**
	 * Persist the per-user sent-reminders map.
	 *
	 * @return void
	 */
	private function saveSentMap(): void {
		try {
			$this->config->setUserValue(
				userId: $this->userId,
				appName: AppIdentity::APP_ID,
				key: self::PREF_SENT,
				value: (string)json_encode($this->sent)
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ComplianceDeadlineCalendarService: failed to persist sent-reminder map',
				['userId' => $this->userId, 'exception' => $e->getMessage()]
			);
		}

	}//end saveSentMap()
}//end class
