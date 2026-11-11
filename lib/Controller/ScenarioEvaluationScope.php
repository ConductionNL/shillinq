<?php

/**
 * Scenario Evaluation Scope
 *
 * The scenario, administration and fiscal year of a scenario evaluation as
 * read from the request.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * What one BudgetScenario evaluation is for.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
 */
final class ScenarioEvaluationScope {
	/**
	 * The scenario id (trimmed path parameter).
	 *
	 * @var string
	 */
	public readonly string $scenarioId;

	/**
	 * The administration id, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The fiscal year; the current year when not given.
	 *
	 * @var integer
	 */
	public readonly int $fiscalYear;

	/**
	 * Read the scope from the request.
	 *
	 * @param IRequest $request    The request.
	 * @param string   $scenarioId The trimmed scenario id.
	 */
	public function __construct(IRequest $request, string $scenarioId) {
		$this->scenarioId = $scenarioId;
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->fiscalYear = (int)$request->getParam('fiscal_year', (string)((int)date('Y')));

	}//end __construct()

	/**
	 * Whether the fiscal year is plausible (2000..2100).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
	 */
	public function plausibleYear(): bool {
		return $this->fiscalYear >= 2000 && $this->fiscalYear <= 2100;
	}//end plausibleYear()
}//end class
