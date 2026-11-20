<?php

/**
 * Contract of VatReturnXbrlBuilder
 *
 * The public surface of VatReturnXbrlBuilder that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of VatReturnXbrlBuilder.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VatReturnXbrlBuilderInterface {

	/**
	 * Build (or rebuild, while it is a draft) the instance of a return, without saving the return.
	 *
	 * For the return's own transition, which saves the return itself. An
	 * instance that left draft is returned as it is: what was validated or
	 * filed is never rewritten.
	 *
	 * @param array<string,mixed> $vatReturn The BtwAangifte.
	 *
	 * @return array<string,mixed> The saved XbrlInstance.
	 *
	 * @throws RuntimeException When the return has no id or no period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.4
	 */
	public function instanceFor(array $vatReturn): array;
}//end interface
