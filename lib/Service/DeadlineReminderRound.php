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

use DateTimeInterface;

/**
 * The user, the reference date, the sent-reminder map and what the round has
 * raised so far. Built and read by ComplianceDeadlineCalendarService only.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineReminderRound {

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
}//end class
