<?php

/**
 * Contract of TaskFieldRealisation
 *
 * The public surface of TaskFieldRealisation that other components call, so they depend
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
 * Contract of TaskFieldRealisation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaskFieldRealisationInterface {

	/**
	 * The year's expenses and income per task field, and the accounts with
	 * postings but no mapping. Amounts are in euros, rounded to the cent.
	 *
	 * @param string $administrationId The municipal administration.
	 * @param string $fiscalYear       The fiscal year, echoed.
	 * @param string $startDate        The year's first day (`Y-m-d`), inclusive.
	 * @param string $endDate          The day after the year (`Y-m-d`), exclusive.
	 *
	 * @return array{
	 *     fiscalYear: string,
	 *     taskFields: list<array{taskField: string, expenses: float, income: float}>,
	 *     unmapped: list<array{accountNumber: string, amount: float, mappingPage: string}>
	 * }
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function forYear(string $administrationId, string $fiscalYear, string $startDate, string $endDate): array;
}//end interface
