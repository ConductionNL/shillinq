<?php

/**
 * Contract of ComplianceDeadlineCalendarService
 *
 * The public surface of ComplianceDeadlineCalendarService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeInterface;

/**
 * Contract of ComplianceDeadlineCalendarService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceDeadlineCalendarServiceInterface {

	/**
	 * Deadline category: BTW / ICP / VPB filing deadlines (REQ-CDC-002).
	 */
	public const CATEGORY_FILING = 'filing';

	/**
	 * Deadline category: payment-run execution dates (REQ-CDC-003).
	 */
	public const CATEGORY_PAYMENT_RUN = 'payment-run';

	/**
	 * Deadline category: AR invoice due dates — per-user opt-in (REQ-CDC-004).
	 */
	public const CATEGORY_AR_DUE = 'ar-due';

	/**
	 * Deadline category: contract renewal / opzegtermijn (REQ-CDC-005).
	 */
	public const CATEGORY_CONTRACT = 'contract';

	/**
	 * All known categories in publication order.
	 *
	 * @var array<int,string>
	 */
	public const CATEGORIES = [
		self::CATEGORY_FILING,
		self::CATEGORY_PAYMENT_RUN,
		self::CATEGORY_AR_DUE,
		self::CATEGORY_CONTRACT,
	];

	/**
	 * Raise NC Notifications for deadlines inside each user's lead time —
	 * exactly one per deadline per user (REQ-CDC-007).
	 *
	 * Dedup is a per-user JSON map (uid => dueDate) in user preferences;
	 * entries for deadlines that left the open set are pruned so the map
	 * stays bounded. A changed dueDate re-arms the reminder (the deadline
	 * genuinely moved).
	 *
	 * @param DateTimeInterface|null $now Reference "today" (injectable for tests).
	 *
	 * @return int Number of notifications raised.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function dispatchDueReminders(?DateTimeInterface $now = null): int;

	/**
	 * Whether a deadline category is enabled for a user (REQ-CDC-006).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 *
	 * @return bool True when the category is enabled.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function isCategoryEnabled(string $userId, string $category): bool;

	/**
	 * The reminder lead time in days for a user + category (REQ-CDC-007).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 *
	 * @return int Lead time in days (>= 0).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function leadTimeDays(string $userId, string $category): int;

	/**
	 * Publish deadline VEVENTs for every seen user (bulk, daily job).
	 *
	 * @return int The number of users processed.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publishAll(): int;

	/**
	 * Publish the deadline VEVENTs for one user, honouring the user's
	 * category toggles (REQ-CDC-001 / REQ-CDC-006).
	 *
	 * Desired state = the open deadlines of every ENABLED category. Stale
	 * app-owned VEVENTs (disabled category, closed source) are overwritten
	 * with STATUS:CANCELLED (see class docblock — OCP exposes no public
	 * delete seam).
	 *
	 * @param string $userId The NC user id whose calendar is targeted.
	 *
	 * @return array{status: string, published: int, removed: int} Outcome —
	 *                                                             status 'ok' or 'failed' (fail-soft, never throws).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publishForUser(string $userId): array;

	/**
	 * Enable / disable a deadline category for a user (REQ-CDC-006).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 * @param bool $enabled The new toggle state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function setCategoryEnabled(string $userId, string $category, bool $enabled): void;

	/**
	 * Set the reminder lead time in days for a user + category.
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 * @param int $days Lead time in days (clamped to >= 0).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function setLeadTimeDays(string $userId, string $category, int $days): void;
}//end interface
