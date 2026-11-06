<?php

/**
 * Compute Fido Quarter Action
 *
 * The `compute` transition of QuartaalrapportageFido: computes the cash limit
 * and the interest risk norm of the report's quarter and keeps both snapshots
 * on the report (REQ-FDO-011).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use InvalidArgumentException;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\PublicSector\FidoQuarter;

/**
 * The `compute` transition's executor.
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 */
class ComputeFidoQuarterAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param FidoQuarter $quarters Computes the Fido figures of a quarter.
	 */
	public function __construct(
		private readonly FidoQuarter $quarters,
	) {
	}//end __construct()

	/**
	 * Return the report with its cash and interest risk snapshots.
	 *
	 * @param array<string,mixed> $objectData   The report with the transition applied.
	 * @param array<string,mixed> $previousData The report before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The report to save.
	 *
	 * @throws InvalidArgumentException When the report names no quarter Q1 to Q4.
	 *
	 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$quarter = (string)($objectData['quarter'] ?? '');
		if (preg_match('/^Q([1-4])$/', $quarter, $match) !== 1) {
			throw new InvalidArgumentException('A Fido report needs a quarter from Q1 to Q4 before it can be computed.');
		}

		$snapshots = $this->quarters->compute(
			organisationId: (string)($objectData['organisationId'] ?? ''),
			year: (string)($objectData['auditYear'] ?? ''),
			quarter: (int)$match[1]
		);
		$objectData['cashStatus'] = $snapshots['cashStatus'];
		$objectData['renteRiskStatus'] = $snapshots['renteRiskStatus'];

		return $objectData;

	}//end execute()
}//end class
