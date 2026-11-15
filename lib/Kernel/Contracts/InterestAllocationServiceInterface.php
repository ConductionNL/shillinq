<?php

/**
 * Contract of InterestAllocationService
 *
 * The public surface of InterestAllocationService that other components call, so they depend
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

use DomainException;

/**
 * Contract of InterestAllocationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InterestAllocationServiceInterface {

	/**
	 * Fill a run's investment and reserve lines and its totals.
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return array<string,mixed> The run with lines, reserveLines and totals.
	 *
	 * @throws DomainException When the year or the percentage is missing.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function calculate(array $run): array;

	/**
	 * Post a calculated run: one journal entry, and a realised addition per reserve.
	 *
	 * @param array<string,mixed> $run The calculated run.
	 *
	 * @return array<string,mixed> The run with journalEntryId.
	 *
	 * @throws DomainException When the run has nothing to post or a reserve lacks its accounts.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function post(array $run): array;
}//end interface
