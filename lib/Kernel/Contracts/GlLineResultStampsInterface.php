<?php

/**
 * Contract of GlLineResultStamps
 *
 * The public surface of GlLineResultStamps that other components call, so they depend
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
 * Contract of GlLineResultStamps.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GlLineResultStampsInterface {

	/**
	 * Stamp one line that was written after its transaction was posted.
	 *
	 * Source documents are booked by writing a transaction already in state
	 * posted and then its lines, so no transition fires for them.
	 *
	 * @param array<string, mixed> $line The line as saved, with its id.
	 *
	 * @return bool True when the line was stamped.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampLine(array $line): bool;

	/**
	 * Stamp a transaction that was reversed, its reversal and the transaction it reverses.
	 *
	 * @param string $transactionId The GLTransaction that moved to reversed.
	 *
	 * @return int The number of lines whose stamp changed.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampReversal(string $transactionId): int;

	/**
	 * Stamp every line of one transaction as its state now says.
	 *
	 * A draft transaction is left alone: its lines carry no stamp, which the
	 * aggregation filter already reads as "does not count".
	 *
	 * @param string $transactionId The GLTransaction id.
	 *
	 * @return int The number of lines whose stamp changed.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampTransaction(string $transactionId): int;
}//end interface
