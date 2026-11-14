<?php

/**
 * The budget, task field and register one BudgetOverrunGuard::canPost reads.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Scope of one budget-overrun check: the programme budget, the taakveld
 * being posted to, and the register the figures live in.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
final class BudgetPostCheck {

	/**
	 * Construct the check.
	 *
	 * @param string $register The register slug.
	 * @param string $budgetId The budget id.
	 * @param string $taskFieldCode The taakveld code.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $budgetId,
		public readonly string $taskFieldCode,
	) {

	}//end __construct()
}//end class
