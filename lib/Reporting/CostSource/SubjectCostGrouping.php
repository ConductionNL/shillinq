<?php

/**
 * Subject Cost Grouping
 *
 * Groups classified subject cost rows per taakveld and per type.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\CostSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\CostSource;

/**
 * Pure grouping of the rows ClassifiedSubjectCostSource reads.
 *
 * A row whose cost is incomplete counts in objects and hours and is listed as
 * incomplete; its cost is never added, so a total is never a partial sum
 * passed off as a whole one.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
 */
final class SubjectCostGrouping {

	/**
	 * The rows grouped per reported taakveld, refinement codes under their parent.
	 *
	 * Rows without a taakveld form one group under the empty code, naming
	 * the types they come from.
	 *
	 * @param array<int, array<string, mixed>> $rows The source rows.
	 * @param TaskFieldRollup $rollup The roll-up.
	 *
	 * @return array<int, array<string, mixed>> Per taakveld: taskField, objects, hours, costCents,
	 *     incomplete (object ids) and types (titles, for the group without a taakveld).
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
	 */
	public static function perTaskField(array $rows, TaskFieldRollup $rollup): array {
		$groups = [];
		foreach ($rows as $row) {
			$taskField = trim((string)($row['taskField'] ?? ''));
			if ($taskField !== '') {
				$taskField = $rollup->reportedUnder(code: $taskField);
			}

			$groups[$taskField] = self::add(group: ($groups[$taskField] ?? ['taskField' => $taskField, 'types' => []]), row: $row);
			if ($taskField === '') {
				$title = trim((string)($row['typeTitle'] ?? ''));
				if ($title !== '' && in_array($title, $groups['']['types'], true) === false) {
					$groups['']['types'][] = $title;
				}
			}
		}

		uksort($groups, static fn (string|int $a, string|int $b): int => self::codeOrder(a: (string)$a, b: (string)$b));

		return array_values($groups);
	}//end perTaskField()

	/**
	 * The rows grouped per type, whether or not the type carries a taakveld.
	 *
	 * @param array<int, array<string, mixed>> $rows The source rows.
	 *
	 * @return array<int, array{typeId: string, typeTitle: string, objects: int, hours: float, costCents: int, incomplete: array<int, string>}>
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function perType(array $rows): array {
		$groups = [];
		foreach ($rows as $row) {
			$typeId = trim((string)($row['typeId'] ?? ''));
			$groups[$typeId] = self::add(
				group: ($groups[$typeId] ?? ['typeId' => $typeId, 'typeTitle' => trim((string)($row['typeTitle'] ?? ''))]),
				row: $row
			);
		}

		uasort(
			$groups,
			static fn (array $a, array $b): int => ([$a['typeTitle'] === '', $a['typeTitle'], $a['typeId']]
				<=> [$b['typeTitle'] === '', $b['typeTitle'], $b['typeId']])
		);

		return array_values($groups);
	}//end perType()

	/**
	 * The hours and complete cost over groups.
	 *
	 * @param array<int, array<string, mixed>> $groups The groups.
	 *
	 * @return array{objects: int, hours: float, costCents: int, incomplete: int}
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function totals(array $groups): array {
		$totals = ['objects' => 0, 'hours' => 0.0, 'costCents' => 0, 'incomplete' => 0];
		foreach ($groups as $group) {
			$totals['objects'] += (int)$group['objects'];
			$totals['hours'] += (float)$group['hours'];
			$totals['costCents'] += (int)$group['costCents'];
			$totals['incomplete'] += count($group['incomplete']);
		}

		$totals['hours'] = round($totals['hours'], 2);

		return $totals;
	}//end totals()

	/**
	 * Add one row to a group.
	 *
	 * @param array<string, mixed> $group The group so far.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The group.
	 */
	private static function add(array $group, array $row): array {
		$group['objects'] = ((int)($group['objects'] ?? 0) + 1);
		$group['hours'] = round(((float)($group['hours'] ?? 0.0) + (float)($row['hours'] ?? 0.0)), 2);
		$group['costCents'] = (int)($group['costCents'] ?? 0);
		$group['incomplete'] = ($group['incomplete'] ?? []);

		$cost = ($row['costCents'] ?? null);
		if (($row['complete'] ?? false) === true && is_int($cost) === true) {
			$group['costCents'] += $cost;
		} else {
			$group['incomplete'][] = (string)($row['objectId'] ?? '');
		}

		return $group;
	}//end add()

	/**
	 * Order taakveld codes numerically per segment, the empty code last.
	 *
	 * @param string $a A code.
	 * @param string $b Another code.
	 *
	 * @return int The comparison.
	 */
	private static function codeOrder(string $a, string $b): int {
		if ($a === '' || $b === '') {
			return (($a === '') <=> ($b === ''));
		}

		return strnatcmp($a, $b);
	}//end codeOrder()
}//end class
