<?php

/**
 * Contract of SmsReminderRunner
 *
 * The public surface of SmsReminderRunner that other components call, so they depend
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
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of SmsReminderRunner.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SmsReminderRunnerInterface {

	/**
	 * Send every due reminder and answer what happened, per outcome status.
	 *
	 * @param DateTimeImmutable $now The time of the run.
	 *
	 * @return array<string, int> Counts: channels, notConnected, and one per send status.
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	public function run(DateTimeImmutable $now): array;

}//end interface
