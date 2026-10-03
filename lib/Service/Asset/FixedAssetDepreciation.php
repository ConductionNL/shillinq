<?php

/**
 * Fixed Asset Depreciation
 *
 * The asset-level side of the schedule (assets-method-change-and-reserve):
 * turns the `revise` and `depreciateExtra` transition inputs into schedule
 * operations, makes a revision's method and useful life the asset's own,
 * clears the one-off inputs, and keeps the asset's monthly depreciation and
 * book value equal to its schedule. Those two fields are written here because
 * their time-based calculations assumed the first plan and are switched off.
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

use DomainException;

/**
 * Revision, extra depreciation and the derived asset fields.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class FixedAssetDepreciation {

	/**
	 * Constructor.
	 *
	 * @param DepreciationScheduleService $schedules The schedule.
	 */
	public function __construct(
		private readonly DepreciationScheduleService $schedules,
	) {
	}//end __construct()

	/**
	 * Apply a revision from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The asset with revisionDate, revisedMethod, revisedUsefulLifeMonths and revisionReason.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @throws DomainException When the date or reason is missing, or the schedule refuses the revision.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function revise(array $asset): array {
		$date = substr(trim((string)($asset['revisionDate'] ?? '')), 0, 10);
		$reason = trim((string)($asset['revisionReason'] ?? ''));
		if ($date === '' || $reason === '') {
			throw new DomainException('A revision needs a date and a reason.');
		}

		$figures = $this->schedules->figures(asset: $asset);
		$method = trim((string)($asset['revisedMethod'] ?? ''));
		if ($method === '') {
			$method = $figures['method'];
		}

		$months = (int)($asset['revisedUsefulLifeMonths'] ?? 0);
		if ($months <= 0) {
			$months = $figures['months'];
		}

		$this->schedules->revise(asset: $asset, date: $date, method: $method, months: $months, reason: $reason);

		$asset['depreciationMethod'] = $method;
		$asset['usefulLifeMonths'] = $months;
		$asset['revisedMethod'] = null;
		$asset['revisedUsefulLifeMonths'] = null;

		return $this->withScheduleFigures(asset: $asset, onDate: $date);

	}//end revise()

	/**
	 * Book an extra depreciation from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The asset with extraDepreciationAmount, extraDepreciationDate and extraDepreciationReason.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @throws DomainException When an input is missing or the schedule refuses the amount.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function extra(array $asset): array {
		$date = substr(trim((string)($asset['extraDepreciationDate'] ?? '')), 0, 10);
		if ($date === '') {
			throw new DomainException('Extra depreciation needs a date.');
		}

		$this->schedules->extra(
			asset: $asset,
			amount: (float)($asset['extraDepreciationAmount'] ?? 0),
			date: $date,
			reason: (string)($asset['extraDepreciationReason'] ?? '')
		);

		$asset['extraDepreciationAmount'] = null;

		return $this->withScheduleFigures(asset: $asset, onDate: $date);

	}//end extra()

	/**
	 * The asset with monthlyDepreciation and currentBookValue taken from its schedule on a date.
	 *
	 * The monthly amount is that of the month the date falls in; the book value
	 * is the cost less every posted line and every planned month that ended
	 * before the date.
	 *
	 * @param array<string,mixed> $asset  The asset.
	 * @param string              $onDate YYYY-MM-DD.
	 *
	 * @return array<string,mixed> The asset with both fields set.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function withScheduleFigures(array $asset, string $onDate): array {
		$figures = $this->schedules->figures(asset: $asset);
		$lines = $this->schedules->lines(assetId: $figures['id']);
		if ($lines === []) {
			return $asset;
		}

		$month = substr($onDate, 0, 7);
		$monthly = 0.0;
		$depreciated = 0;
		foreach ($lines as $line) {
			$start = (string)($line['periodStartDate'] ?? '');
			$isExtra = (string)($line['rateType'] ?? '') === DepreciationScheduleService::EXTRA;
			if ($isExtra === false && substr($start, 0, 7) === $month) {
				$monthly = (float)($line['depreciationAmount'] ?? 0);
			}

			if ((string)($line['status'] ?? '') === DepreciationScheduleService::POSTED || (string)($line['periodEndDate'] ?? '') < $onDate) {
				$depreciated += (int)round(((float)($line['depreciationAmount'] ?? 0)) * 100);
			}
		}

		$asset['monthlyDepreciation'] = $monthly;
		$asset['currentBookValue'] = (($figures['costCents'] - $depreciated) / 100);

		return $asset;

	}//end withScheduleFigures()
}//end class
