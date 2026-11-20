<?php

/**
 * Contract of AnnualAccountsXbrlBuilder
 *
 * The public surface of AnnualAccountsXbrlBuilder that other components call, so they depend
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
 * Contract of AnnualAccountsXbrlBuilder.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnnualAccountsXbrlBuilderInterface {

	/**
	 * Build (or rebuild, while it is a draft) the KvK instance of a statement.
	 *
	 * An instance of the statement that left draft is returned as it is.
	 *
	 * @param array<string,mixed> $statement        The FinancialStatement.
	 * @param string              $administrationId The administration it belongs to.
	 *
	 * @return array<string,mixed> The saved XbrlInstance.
	 *
	 * @throws RuntimeException When the statement has no id or no reporting period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public function build(array $statement, string $administrationId): array;
}//end interface
