<?php

/**
 * The inputs one budget grid row's cells are computed from.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The LedgerGroup key and the loaded grid context BudgetGridCalculator's
 * cell helpers share.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 */
final class BudgetGridCellRun {

	/**
	 * Construct one run.
	 *
	 * @param string                                 $ledgerGroupKey          The row's LedgerGroup key.
	 * @param array<string,mixed>                    $bvaContext              The BudgetVsActualsReader context.
	 * @param array<int|string,array<int,mixed>|null> $budgetLinesByFiscalYear BudgetLines per fiscal year, null when unbudgeted.
	 * @param array<string,string>                   $accountTypeByNumber     Account types by number.
	 */
	public function __construct(
		public readonly string $ledgerGroupKey,
		public readonly array $bvaContext,
		public readonly array $budgetLinesByFiscalYear,
		public readonly array $accountTypeByNumber,
	) {
	}//end __construct()
}//end class
