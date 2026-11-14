<?php

/**
 * Contract of SubjectCostService
 *
 * The public surface of SubjectCostService that other components call, so they depend
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
 * Contract of SubjectCostService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubjectCostServiceInterface {

	/**
	 * The employer cost of one domain object's booked hours.
	 *
	 * @param string $subjectApp The owning app, as written on the hour row.
	 * @param string $subjectId The domain object id, as written on the hour row.
	 * @param array<int, string>|null $administrationIds Administrations the caller
	 *     may read, or null for an unrestricted (Nextcloud admin) read.
	 * @param string $period Costing period `YYYY-MM`, or '' for the current rate.
	 *
	 * @return array{
	 *     subjectApp: string, subjectId: string, hours: float, costCents: int|null,
	 *     complete: bool, currency: string, unscopedRowsExcluded: int,
	 *     perPerson: array<int, array{personId: string, hours: float, centsPerHour: int|null, costCents: int|null}>,
	 *     unpricedPersonIds: array<int, string>
	 * } The aggregate, echoing the subject it describes.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	public function costFor(
		string $subjectApp,
		string $subjectId,
		?array $administrationIds,
		string $period = '',
	): array;
}//end interface
