<?php

/**
 * Contract of SuggestedMappingConfirmation
 *
 * The public surface of SuggestedMappingConfirmation that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of SuggestedMappingConfirmation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SuggestedMappingConfirmationInterface {

	/**
	 * Confirm every unconfirmed mapping row with a target account.
	 *
	 * @param string $batchId The import batch id.
	 *
	 * @return int How many rows were confirmed.
	 *
	 * @throws RuntimeException When the batch does not exist or the caller is no member of its administration.
	 * @throws DomainException When the batch is not in the mapping state.
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function confirm(string $batchId): int;

}//end interface
