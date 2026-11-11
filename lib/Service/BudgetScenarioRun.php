<?php

/**
 * The fixed inputs of one budget scenario evaluation.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The LedgerGroup index, the real CashflowRecurring rows by recurId and the
 * fiscal year that BudgetScenarioEvaluator's helpers share.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
 */
final class BudgetScenarioRun {

	/**
	 * Construct one run.
	 *
	 * @param array<string,mixed>                $index              The LedgerGroup index.
	 * @param array<string,array<string,mixed>>  $recurringByRecurId Real CashflowRecurring rows by recurId.
	 * @param int                                $fiscalYear         The fiscal year evaluated.
	 */
	public function __construct(
		public readonly array $index,
		public readonly array $recurringByRecurId,
		public readonly int $fiscalYear,
	) {
	}//end __construct()
}//end class
