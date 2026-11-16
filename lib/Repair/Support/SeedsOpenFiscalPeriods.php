<?php

/**
 * Shillinq repair-step open FiscalPeriod seeding helper.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair\Support;

/**
 * The existence check, record shape and month names shared by the repair
 * steps that seed open FiscalPeriod records.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
 */
trait SeedsOpenFiscalPeriods {
	/**
	 * Whether a FiscalPeriod record already exists for the given
	 * (periodId, administrationId) tuple. An empty administrationId
	 * matches on periodId alone.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $periodId The business periodId.
	 * @param string $administrationId The administration scope ('' for none).
	 *
	 * @return bool True when a match is found.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
	 */
	private function fiscalPeriodExists(
		object $objectService,
		string $registerSlug,
		string $periodId,
		string $administrationId,
	): bool {
		$filters = ['periodId' => $periodId];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $objectService
			->setRegister($registerSlug)
			->setSchema('FiscalPeriod')
			->findAll(['filters' => $filters, 'limit' => 1]);

		return is_array($found) === true && $found !== [];
	}//end fiscalPeriodExists()

	/**
	 * Build the open FiscalPeriod record for one period.
	 *
	 * @param array{periodId:string,name:string,startDate:string,endDate:string,fiscalYear:int} $period           The period.
	 * @param string                                                                            $administrationId The administration scope.
	 *
	 * @return array<string,mixed> The FiscalPeriod record (state: open).
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
	 */
	private function openFiscalPeriodRecord(array $period, string $administrationId): array {
		return [
			'periodId' => $period['periodId'],
			'name' => $period['name'],
			'administrationId' => $administrationId,
			'startDate' => $period['startDate'],
			'endDate' => $period['endDate'],
			'fiscalYear' => $period['fiscalYear'],
			'state' => 'open',
			'reopenedHistory' => [],
			'taskChecklistItems' => [],
			'aiFlags' => [],
		];
	}//end openFiscalPeriodRecord()

	/**
	 * English name of a calendar month.
	 *
	 * @param int $month Month number (1-12).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
	 */
	private function monthName(int $month): string {
		$names = [
			1 => 'January',
			2 => 'February',
			3 => 'March',
			4 => 'April',
			5 => 'May',
			6 => 'June',
			7 => 'July',
			8 => 'August',
			9 => 'September',
			10 => 'October',
			11 => 'November',
			12 => 'December',
		];

		return $names[$month];
	}//end monthName()
}//end trait
