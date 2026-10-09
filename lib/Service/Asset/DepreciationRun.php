<?php

/**
 * Depreciation Run
 *
 * The daily pass of DepreciationRunJob (assets-method-change-and-reserve,
 * REQ-AMCR-001, REQ-AMCR-005): posts the planned lines of the month that ended
 * last, one journal entry per administration; brings every active asset's
 * monthly depreciation and book value in line with its schedule; and releases
 * the reinvestment reserves that expired. A line already posted is never
 * posted again, so a second pass on the same day posts nothing. Months before
 * the last one are not caught up here: the asset page's missed depreciation
 * lists them and posts them on request.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

use DateTimeImmutable;

/**
 * One pass: post, refresh, release.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class DepreciationRun {

	/**
	 * Constructor.
	 *
	 * @param DepreciationScheduleService $schedules    The schedule.
	 * @param FixedAssetDepreciation      $depreciation The asset fields.
	 * @param ReinvestmentReserves        $reserves     The reserves.
	 * @param AssetRecords                $records      The register.
	 */
	public function __construct(
		private readonly DepreciationScheduleService $schedules,
		private readonly FixedAssetDepreciation $depreciation,
		private readonly ReinvestmentReserves $reserves,
		private readonly AssetRecords $records,
	) {
	}//end __construct()

	/**
	 * Run the pass for a day.
	 *
	 * @param DateTimeImmutable $today The day.
	 *
	 * @return array{month: string, journals: int, lines: int, assets: int, released: int} What the pass did.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function run(DateTimeImmutable $today): array {
		$month = $today->modify('first day of last month')->format('Y-m');
		$posted = $this->schedules->postMonth(month: $month);

		$refreshed = 0;
		$date = $today->format('Y-m-d');
		foreach ($this->records->records(schema: DepreciationScheduleService::ASSET, filters: ['status' => 'active']) as $asset) {
			$updated = $this->depreciation->withScheduleFigures(asset: $asset, onDate: $date);
			$changes = array_diff_assoc(
				['monthlyDepreciation' => (string)($updated['monthlyDepreciation'] ?? ''), 'currentBookValue' => (string)($updated['currentBookValue'] ?? '')],
				['monthlyDepreciation' => (string)($asset['monthlyDepreciation'] ?? ''), 'currentBookValue' => (string)($asset['currentBookValue'] ?? '')]
			);
			if ($changes === []) {
				continue;
			}

			$this->records->patch(
				schema: DepreciationScheduleService::ASSET,
				id: (string)$asset['id'],
				fields: ['monthlyDepreciation' => $updated['monthlyDepreciation'], 'currentBookValue' => $updated['currentBookValue']]
			);
			$refreshed++;
		}//end foreach

		return [
			'month'    => $month,
			'journals' => $posted['journals'],
			'lines'    => $posted['lines'],
			'assets'   => $refreshed,
			'released' => $this->reserves->releaseExpired(today: $date),
		];

	}//end run()
}//end class
