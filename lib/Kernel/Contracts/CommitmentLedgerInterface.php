<?php

/**
 * Contract of CommitmentLedger
 *
 * The public surface of CommitmentLedger that other components call, so they depend
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

/**
 * Contract of CommitmentLedger.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitmentLedgerInterface {

	/**
	 * The budget of a programme in a year, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $programme        The programme code.
	 * @param int    $year             The financial year.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function budget(string $administrationId, string $programme, int $year): ?array;

	/**
	 * Close a commitment: release what remains to the budgets.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param string              $user       Who acted.
	 *
	 * @return int The released amount in cents.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.1
	 */
	public function closed(array $commitment, string $user): int;

	/**
	 * Record a commitment entered into: the movement, the remaining amounts and the budgets.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param string              $user       Who acted.
	 *
	 * @return int The amount recorded in cents, 0 when it was recorded before.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-1.2
	 */
	public function committed(array $commitment, string $user): int;

	/**
	 * Free capacity of a budget: authorised minus realised minus outstanding.
	 *
	 * @param array<string,mixed> $budget The budget.
	 *
	 * @return int Cents.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function free(array $budget): int;

	/**
	 * The lines of a commitment that are not closed.
	 *
	 * @param string $commitmentNumber The commitment number.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function openLines(string $commitmentNumber): array;
}//end interface
