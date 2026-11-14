<?php

/**
 * Contract of TenderNedStatusSync
 *
 * The public surface of TenderNedStatusSync that other components call, so they depend
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
 * Contract of TenderNedStatusSync.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenderNedStatusSyncInterface {

	/**
	 * Sync the completion of an eindoplevering back to TenderNed (REQ-006).
	 *
	 * Called by `OrderFulfilmentTransitionListener` when the approved
	 * eindoplevering for a `bron: tenderned` obligation transitions to
	 * `completed`. Returns true when the outbound call was attempted (the
	 * live transport may still fail downstream — the contract guarantees
	 * a "best-effort" send + structured log only).
	 *
	 * @param array<string, mixed> $oplevering Completed OrderFulfilment payload.
	 *
	 * @return bool True when a sync attempt was made; false when ineligible.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-6
	 */
	public function syncCompletion(array $oplevering): bool;
}//end interface
