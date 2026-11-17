<?php

/**
 * Contract of XbrlTaxonomyLookup
 *
 * The public surface of XbrlTaxonomyLookup that other components call, so they depend
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
 * Contract of XbrlTaxonomyLookup.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XbrlTaxonomyLookupInterface {

	/**
	 * The taxonomy effective for a period that carries an entry point, or null.
	 *
	 * @param string $entryPoint The entry point.
	 * @param string $start      The first day of the period (Y-m-d).
	 * @param string $end        The last day of the period (Y-m-d).
	 *
	 * @return array<string, mixed>|null The taxonomy record.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function effectiveFor(string $entryPoint, string $start, string $end): ?array;
}//end interface
