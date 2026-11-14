<?php

/**
 * Contract of ReserveBalances
 *
 * The public surface of ReserveBalances that other components call, so they depend
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
 * Contract of ReserveBalances.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReserveBalancesInterface {

	/**
	 * The multi-year overview of an administration's reserves.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The first year shown.
	 *
	 * @return array{fromYear:int,rows:list<array<string,mixed>>}
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function overview(string $administrationId, int $fromYear): array;

	/**
	 * Book a mutation that is being realised and answer it with its journal entry.
	 *
	 * @param array<string,mixed> $mutation The mutation, status realised.
	 *
	 * @return array<string,mixed> The mutation with journalEntryId.
	 *
	 * @throws DomainException When the reserve or its accounts are missing, or the amount is not positive.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function realise(array $mutation): array;
}//end interface
