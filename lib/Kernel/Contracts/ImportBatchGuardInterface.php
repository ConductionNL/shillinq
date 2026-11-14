<?php

/**
 * Contract of ImportBatchGuard
 *
 * The public surface of ImportBatchGuard that other components call, so they depend
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

/**
 * Contract of ImportBatchGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ImportBatchGuardInterface {

	/**
	 * Whether a posted batch may be reversed (REQ-AIM-009).
	 *
	 * True only when the batch is in the posted state AND the target period is
	 * still open. Fail-closed: a closed period or any non-posted state denies,
	 * so reversal can never unwind books in a closed period.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 * @param bool $periodOpen Whether the target FiscalPeriod is open
	 *                         (resolved by the caller via the
	 *                         bookkeeping-period-close surface).
	 *
	 * @return bool True only for a posted batch in an open period.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
	 */
	public function canReverse(array $batch, bool $periodOpen): bool;
}//end interface
