<?php

/**
 * Assignment Hours
 *
 * Keeps the hours budget of a project assignment current (people-hours-budget
 * REQ-PHB-001, REQ-PHB-002): the logged hours are the sum of the time
 * registrations booked on the assignment, the remaining hours follow from the
 * estimate, and the two warned flags go true once when the used percentage
 * reaches 80 and 100. The flags are what the declared notifications on
 * ProjectAssignment listen to, so each warning is sent once per threshold;
 * a changed estimate clears them first, which arms them again.
 *
 * The project (engagement) gets the totals over its assignments and
 * hoursOverBudget, which the projects list filters on (REQ-PHB-003).
 *
 * The hour record is named in one constant: the open change hours-to-humaniq
 * moves hours to humaniq, and the read path it settles on replaces it there.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Project
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

namespace OCA\Shillinq\Service\Project;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Sums the hours booked on an assignment and writes its hours budget.
 */
class AssignmentHours {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema holding one booked hour, with `projectAssignmentId` and `hours`.
	 */
	public const HOUR_SCHEMA = 'UrenRegistratie';

	/**
	 * The assignment schema.
	 */
	public const ASSIGNMENT_SCHEMA = 'ProjectAssignment';

	/**
	 * The project schema the assignments point at with `projectId`.
	 */
	public const PROJECT_SCHEMA = 'engagement';

	/**
	 * Warning thresholds in percent, by the flag each one sets.
	 */
	public const THRESHOLDS = [
		'hoursWarnedAt80'  => 80.0,
		'hoursWarnedAt100' => 100.0,
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param SettingsService        $settings      Resolves the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Re-sum one assignment and write its hours budget and its project's totals.
	 *
	 * @param string      $assignmentId  The ProjectAssignment id.
	 * @param bool        $rearm         True when the estimate changed: the warned flags are cleared first.
	 * @param string|null $ignoreHourId  An hour record to leave out (one being deleted).
	 *
	 * @return bool True when the assignment was patched.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function recalculate(string $assignmentId, bool $rearm = false, ?string $ignoreHourId = null): bool {
		$assignment = $this->assignment(assignmentId: $assignmentId);
		if ($assignment === null) {
			return false;
		}

		if ($rearm === true && $this->anyFlagSet(assignment: $assignment) === true) {
			// Two writes: a flag that stays true in one write is no change, so
			// the declared warning would not fire again for the new estimate.
			$cleared = array_fill_keys(array_keys(self::THRESHOLDS), false);
			$this->patch(schema: self::ASSIGNMENT_SCHEMA, objectId: $assignmentId, data: $cleared);
			$assignment = array_merge($assignment, $cleared);
		}

		$logged = $this->loggedHours(assignmentId: $assignmentId, ignoreHourId: $ignoreHourId);
		$budget = $this->budget(assignment: $assignment, logged: $logged);
		$budget = array_merge($budget, $this->projectFields(projectId: (string)($assignment['projectId'] ?? '')));

		$changed = $this->changedFields(current: $assignment, wanted: $budget);
		if ($changed !== []) {
			$this->patch(schema: self::ASSIGNMENT_SCHEMA, objectId: $assignmentId, data: $changed);
		}

		$this->recalculateProject(projectId: (string)($assignment['projectId'] ?? ''));

		return $changed !== [];

	}//end recalculate()

	/**
	 * The hours budget fields for an assignment with this many logged hours.
	 *
	 * @param array<string, mixed> $assignment The assignment as stored.
	 * @param float                $logged     The logged hours.
	 *
	 * @return array<string, mixed> loggedHours, remainingHours and the warned flags.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function budget(array $assignment, float $logged): array {
		$estimate = $assignment['estimatedHours'] ?? null;
		$percent = $this->usedPercent(estimate: $estimate, logged: $logged);
		$fields = [
			'loggedHours'    => $logged,
			'remainingHours' => null,
		];
		if ($percent !== null) {
			$fields['remainingHours'] = round((float)$estimate - $logged, 2);
		}

		foreach (self::THRESHOLDS as $flag => $threshold) {
			$fields[$flag] = (($assignment[$flag] ?? false) === true) || ($percent !== null && $percent >= $threshold);
		}

		return $fields;

	}//end budget()

	/**
	 * Logged hours as a percentage of the estimate, one decimal; null without an estimate.
	 *
	 * Mirrors the declared hoursUsedPercent calculation.
	 *
	 * @param mixed $estimate The estimated hours.
	 * @param float $logged   The logged hours.
	 *
	 * @return float|null The percentage.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function usedPercent(mixed $estimate, float $logged): ?float {
		if (is_numeric($estimate) === false || (float)$estimate <= 0.0) {
			return null;
		}

		return round($logged / (float)$estimate * 100, 1);

	}//end usedPercent()

	/**
	 * The ids of every assignment, for the repair step.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function assignmentIds(): array {
		$ids = [];
		foreach ($this->rows(schema: self::ASSIGNMENT_SCHEMA, filters: []) as $row) {
			$id = ObjectIdentifier::resolve(saved: $row);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;

	}//end assignmentIds()

	/**
	 * Write the totals over a project's assignments onto the project.
	 *
	 * @param string $projectId The engagement id.
	 *
	 * @return void
	 */
	private function recalculateProject(string $projectId): void {
		$project = $this->find(schema: self::PROJECT_SCHEMA, objectId: $projectId);
		if ($project === null) {
			return;
		}

		$estimated = 0.0;
		$logged = 0.0;
		$over = false;
		foreach ($this->rows(schema: self::ASSIGNMENT_SCHEMA, filters: ['projectId' => $projectId]) as $row) {
			$data = $this->rowPayload(row: $row);
			$estimated += (float)($data['estimatedHours'] ?? 0);
			$logged += (float)($data['loggedHours'] ?? 0);
			$percent = $this->usedPercent(estimate: ($data['estimatedHours'] ?? null), logged: (float)($data['loggedHours'] ?? 0));
			$over = $over || ($percent !== null && $percent > 100.0);
		}

		$totals = [
			'estimatedHoursTotal' => $estimated,
			'loggedHoursTotal'    => $logged,
			'remainingHoursTotal' => round($estimated - $logged, 2),
			'hoursOverBudget'     => $over,
		];
		$changed = $this->changedFields(current: $project, wanted: $totals);
		if ($changed !== []) {
			$this->patch(schema: self::PROJECT_SCHEMA, objectId: $projectId, data: $changed);
		}

	}//end recalculateProject()

	/**
	 * The project owner and name copied onto the assignment for the warnings.
	 *
	 * @param string $projectId The engagement id.
	 *
	 * @return array<string, mixed> projectOwner and projectTitle, empty when the project is unknown.
	 */
	private function projectFields(string $projectId): array {
		$project = $this->find(schema: self::PROJECT_SCHEMA, objectId: $projectId);
		if ($project === null) {
			return [];
		}

		return [
			'projectOwner' => ($project['responsibleUser'] ?? null),
			'projectTitle' => ($project['name'] ?? null),
		];

	}//end projectFields()

	/**
	 * The sum of the hours booked on an assignment.
	 *
	 * @param string      $assignmentId The ProjectAssignment id.
	 * @param string|null $ignoreHourId An hour record to leave out.
	 *
	 * @return float The hours.
	 */
	private function loggedHours(string $assignmentId, ?string $ignoreHourId): float {
		$sum = 0.0;
		foreach ($this->rows(schema: self::HOUR_SCHEMA, filters: ['projectAssignmentId' => $assignmentId]) as $row) {
			if ($ignoreHourId !== null && ObjectIdentifier::resolve(saved: $row) === $ignoreHourId) {
				continue;
			}

			$sum += (float)($this->rowPayload(row: $row)['hours'] ?? 0);
		}

		return round($sum, 2);

	}//end loggedHours()

	/**
	 * Whether a warned flag is set on the assignment.
	 *
	 * @param array<string, mixed> $assignment The assignment.
	 *
	 * @return bool True when one is.
	 */
	private function anyFlagSet(array $assignment): bool {
		foreach (array_keys(self::THRESHOLDS) as $flag) {
			if (($assignment[$flag] ?? false) === true) {
				return true;
			}
		}

		return false;

	}//end anyFlagSet()

	/**
	 * The wanted fields whose value differs from the stored one.
	 *
	 * @param array<string, mixed> $current The stored object.
	 * @param array<string, mixed> $wanted  The wanted values.
	 *
	 * @return array<string, mixed> The fields to patch.
	 */
	private function changedFields(array $current, array $wanted): array {
		$changed = [];
		foreach ($wanted as $key => $value) {
			$stored = ($current[$key] ?? null);
			if (is_numeric($stored) === true && is_float($value) === true && (float)$stored === $value) {
				continue;
			}

			if ($stored !== $value) {
				$changed[$key] = $value;
			}
		}

		return $changed;

	}//end changedFields()

	/**
	 * One assignment by id.
	 *
	 * @param string $assignmentId The id.
	 *
	 * @return array<string, mixed>|null The assignment.
	 */
	private function assignment(string $assignmentId): ?array {
		return $this->find(schema: self::ASSIGNMENT_SCHEMA, objectId: $assignmentId);

	}//end assignment()

	/**
	 * One object's payload by id, null when absent.
	 *
	 * @param string $schema   The schema slug.
	 * @param string $objectId The id.
	 *
	 * @return array<string, mixed>|null The payload.
	 */
	private function find(string $schema, string $objectId): ?array {
		if ($objectId === '') {
			return null;
		}

		$found = $this->objectService->find(
			id: $objectId,
			register: $this->settings->getRegisterSlug(),
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);
		if ($found === null) {
			return null;
		}

		return $this->rowPayload(row: $found);

	}//end find()

	/**
	 * Patch fields of one object.
	 *
	 * @param string               $schema   The schema slug.
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The fields.
	 *
	 * @return void
	 */
	private function patch(string $schema, string $objectId, array $data): void {
		$this->objectService->patchObject(
			objectId: $objectId,
			data: $data,
			register: $this->settings->getRegisterSlug(),
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

	}//end patch()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rows(string $schema, array $filters): array {
		return $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->settings->getRegisterSlug(),
			schema: $schema,
			filters: $filters
		);

	}//end rows()
}//end class
