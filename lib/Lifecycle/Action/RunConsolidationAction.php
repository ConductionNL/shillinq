<?php

/**
 * Run Consolidation Action
 *
 * The `runConsolidation` transition of ConsolidationPeriod: sums the group's
 * administrations for the period, applies its eliminations, stores the
 * outcome on the period's consolidated balance and income statement, and
 * keeps the moment of the run on the period (REQ-CONS-011).
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
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\ConsolidationRunService;

/**
 * The `runConsolidation` transition's executor.
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
 */
class RunConsolidationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ConsolidationRunService $runs Runs the consolidation of a period.
	 */
	public function __construct(
		private readonly ConsolidationRunService $runs,
	) {
	}//end __construct()

	/**
	 * Run the consolidation and return the period with the moment of the run.
	 *
	 * @param array<string,mixed> $objectData   The period with the transition applied.
	 * @param array<string,mixed> $previousData The period before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The period to save.
	 *
	 * @throws InvalidArgumentException When the period cannot be run (see ConsolidationRunService::run()).
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$asOf = new DateTimeImmutable();
		$this->runs->run(period: $objectData, asOf: $asOf);

		// The elimination totals stay the period's declared aggregations over
		// EliminationEntry; only the moment of the run is kept.
		$objectData['executionTimestamp'] = $asOf->format(DateTimeInterface::ATOM);

		return $objectData;

	}//end execute()
}//end class
