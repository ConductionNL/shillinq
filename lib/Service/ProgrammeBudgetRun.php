<?php

/**
 * The scope and filters of one programme budget-vs-actuals request.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Accessible administrations, the resolved fiscal-year window and the caller's
 * programme and budget-status filters for one dashboard envelope.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
final class ProgrammeBudgetRun {

	/**
	 * Construct the run.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window The fiscal-year window.
	 * @param array<int,string>|null $programmes Programme filter; null = every programme.
	 * @param array<int,string>|null $statuses Budget-status filter; null = every status.
	 */
	public function __construct(
		public readonly array $administrationIds,
		public readonly array $window,
		public readonly array|null $programmes,
		public readonly array|null $statuses,
	) {

	}//end __construct()
}//end class
