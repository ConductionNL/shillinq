<?php

/**
 * Contract of IntercompanyJournalService
 *
 * The public surface of IntercompanyJournalService that other components call, so they depend
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
 * Contract of IntercompanyJournalService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntercompanyJournalServiceInterface {

	/**
	 * Whether the source and destination sides balance within a one-cent tolerance.
	 *
	 * @param float|int|string $sourceAmount The booked source amount.
	 * @param float|int|string $destinationAmount The booked destination amount.
	 *
	 * @return bool True when the two sides reconcile.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
	 */
	public function isBalanced(float|int|string $sourceAmount, float|int|string $destinationAmount): bool;
}//end interface
