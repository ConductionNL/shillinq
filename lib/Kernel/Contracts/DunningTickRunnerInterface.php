<?php

/**
 * Contract of DunningTickRunner
 *
 * The public surface of DunningTickRunner that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of DunningTickRunner.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DunningTickRunnerInterface {

	/**
	 * The Administration property that switches automatic reminders on.
	 */
	public const ENABLED_PROPERTY = 'dunningEnabled';

	/**
	 * The last stored run report of one administration, found under its code
	 * or its record id.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return array<string,mixed>|null The report, or null when the job never ran it.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	public function lastReportFor(array $administration): ?array;

	/**
	 * The Next run preview of one administration (REQ-RAD-008): per invoice
	 * the next run would chase, the stage and channel it would send, by the
	 * job's own choice. Reads the same invoices as the job (issued and past
	 * due, and overdue) and writes nothing, whether dunning is on or not.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 * @param DateTimeImmutable   $now            The moment to judge by.
	 *
	 * @return array<int,array<string,mixed>> invoiceId, invoiceNumber, customer, dueDate, amount, stageNr, channel, ladderId.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	public function previewAdministration(array $administration, DateTimeImmutable $now): array;

	/**
	 * Run the pass for every administration with automatic reminders on.
	 *
	 * The pass runs as the system. Cron has no session user, and OpenRegister
	 * treats a userless caller as the system only on the command line or
	 * inside runAsSystem(): under AJAX or webcron every read that keeps
	 * organisation scoping on (all of DunningRunService's, and the transition
	 * engine's) answers nothing, so no ladder was found and nothing was sent.
	 *
	 * @param DateTimeImmutable $now The moment of the run.
	 *
	 * @return array<int,array<string,mixed>> One report per administration that was enabled.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
	 */
	public function runAll(DateTimeImmutable $now): array;
}//end interface
