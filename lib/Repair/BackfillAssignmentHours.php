<?php

/**
 * Backfill Assignment Hours
 *
 * Sums the hours booked before the hours listener existed into every
 * assignment's loggedHours, once per upgrade (people-hours-budget, design
 * Migration Plan). Re-running it changes nothing: an assignment whose sum
 * already matches is not written.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\Project\AssignmentHours;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes loggedHours on every assignment from the hours already booked.
 */
class BackfillAssignmentHours implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param AssignmentHours $hours  Sums and writes the hours budget.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly AssignmentHours $hours,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Shillinq: sum the hours booked on every project assignment';

	}//end getName()

	/**
	 * Re-sum every assignment.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$assignments = 0;
			$updated = 0;
			foreach ($this->hours->assignmentIds() as $assignmentId) {
				$assignments++;
				if ($this->hours->recalculate(assignmentId: $assignmentId) === true) {
					$updated++;
				}
			}

			$output->info(sprintf('Shillinq: logged hours written on %d of %d project assignment(s).', $updated, $assignments));
		} catch (Throwable $e) {
			$output->warning('Shillinq: summing the assignment hours failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: summing the assignment hours failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
